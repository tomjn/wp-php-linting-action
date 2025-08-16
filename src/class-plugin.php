<?php
/**
 * Composer plugin to expose scripts from particular packages in the root.
 *
 * @package Tomjn
 */

declare(strict_types=1);

namespace Tomjn\SharedScripts;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Plugin\CommandEvent;

/**
 * Composer plugin class to merge select run scripts into the root.
 */
final class Plugin implements PluginInterface, EventSubscriberInterface {

	/**
	 * Main composer object.
	 *
	 * @var Composer
	 */
	private Composer $composer;

	/**
	 * Main IO interface.
	 *
	 * @var IOInterface
	 */
	private IOInterface $io;

	/**
	 * Are we finished?
	 *
	 * @var boolean
	 */
	private bool $done = false;

	/**
	 * Activate plugin and inject dependencies.
	 *
	 * @param Composer    $composer Composer.
	 * @param IOInterface $io IO Interface.
	 * @return void
	 */
	public function activate( Composer $composer, IOInterface $io ): void {
		$this->composer = $composer;
		$this->io       = $io;
	}

	/**
	 * Register for command runs events.
	 *
	 * @return array
	 */
	public static function getSubscribedEvents(): array {
		return array(
			PluginEvents::PRE_COMMAND_RUN => array( 'injectScripts', 100 ),
		);
	}

	/**
	 * Inject the new scripts when the run command occurs.
	 *
	 * @param CommandEvent $event command event.
	 * @return void
	 */
	public function injectScripts( CommandEvent $event ): void {
		if ( $this->done ) {
			return;
		}

		$cmd = $event->getCommandName();
		if ( ( 'run-script' !== $cmd ) && ( 'run' !== $cmd ) ) {
			return; // only affect composer run/run-script.
		}

		$root      = $this->composer->getPackage();
		$root_name = $root->getName() ? $root->getName() : '__root__';

		// Root-level manager config.
		$root_extra = $root->getExtra();
		$mgr        = is_array( $root_extra['shared-scripts'] ?? null ) ? $root_extra['shared-scripts'] : array();

		// Default allow list, if unset, defaults to humanmade/*.
		$allow_patterns = array_key_exists( 'allow', $mgr )
			? $this->normalizeStringArray( $mgr['allow'] )
			: array( 'tomjn/*' );

		$deny_patterns     = $this->normalizeStringArray( $mgr['deny'] ?? array() );
		$default_collision = (string) ( $mgr['default-collision'] ?? 'keep-root' ); // keep-root|chain|replace-nonroot.
		$prefixes          = is_array( $mgr['prefixes'] ?? null ) ? $mgr['prefixes'] : array();
		$delimiter         = is_string( $mgr['delimiter'] ?? null ) && ( '' !== $mgr['delimiter'] ) ? (string) $mgr['delimiter'] : ':';
		$verbose           = (bool) ( $mgr['verbose'] ?? false );

		$merged  = $root->getScripts();
		$sources = array();
		foreach ( array_keys( $merged ) as $k ) {
			$sources[ $k ] = array(
				'pkg'      => $root_name,
				'priority' => PHP_INT_MIN,
				'isRoot'   => true,
			);
		}

		$repo     = $this->composer->getRepositoryManager()->getLocalRepository();
		$packages = $repo->getPackages();

		$candidates = array();
		foreach ( $packages as $pkg ) {
			$pkg_name = $pkg->getName();
			if ( $pkg_name === $root_name ) {
				continue;
			}

			// deny overrides.
			if ( $this->matchesAny( $pkg_name, $deny_patterns ) ) {
				if ( $verbose ) {
					$this->io->write( "shared-scripts: denied $pkg_name" );
				}
				continue;
			}
			// must match allow list.
			if ( ! $this->matchesAny( $pkg_name, $allow_patterns ) ) {
				if ( $verbose ) {
					$this->io->write( "shared-scripts: not allowed $pkg_name" );
				}
				continue;
			}

			$extra = $pkg->getExtra();
			$conf  = $extra['shared-scripts'] ?? null;
			if ( ! is_array( $conf ) ) {
				continue; // only opt-in packages are considered.
			}
			if ( isset( $conf['enabled'] ) && false === $conf['enabled'] ) {
				continue;
			}

			$scripts = $conf['scripts'] ?? null;
			if ( ! is_array( $scripts ) || array() === $scripts ) {
				continue;
			}

			$pkg_prefix    = (string) ( $prefixes[ $pkg_name ] ?? ( $conf['prefix'] ?? '' ) );
			$pkg_delimiter = isset( $conf['delimiter'] ) && is_string( $conf['delimiter'] ) && ( '' !== $conf['delimiter'] ) ? (string) $conf['delimiter'] : $delimiter;
			$prio          = (int) ( $conf['priority'] ?? 100 );
			$collision     = (string) ( $conf['collision'] ?? $default_collision ); // keep-root|chain|replace-nonroot.

			foreach ( $scripts as $name => $value ) {
				$final_name = $this->applyPrefix( (string) $name, $pkg_prefix, $pkg_delimiter );
				if ( ! $this->isValidScriptName( $final_name ) ) {
					if ( $verbose ) {
						$this->io->warning( "shared-scripts: skipped invalid script name '$final_name' from $pkg_name" );
					}
					continue;
				}
				$norm = $this->normalizeScriptValue( $value );
				if ( null === $norm ) {
					if ( $verbose ) {
						$this->io->warning( "shared-scripts: skipped invalid script value for '$final_name' from $pkg_name" );
					}
					continue;
				}
				$candidatesarray[] = array(
					'name'      => $final_name,
					'value'     => $norm,
					'pkg'       => $pkg_name,
					'prio'      => $prio,
					'collision' => $collision,
				);
			}
		}

		// Deterministic: priority asc, package name asc, script name asc.
		usort(
			$candidates,
			function ( $a, $b ) {
				if ( $a['prio'] !== $b['prio'] ) {
					return $a['prio'] <=> $b['prio'];
				}
				if ( $a['pkg'] !== $b['pkg'] ) {
					return strcmp( $a['pkg'], $b['pkg'] );
				}
				return strcmp( $a['name'], $b['name'] );
			}
		);

		foreach ( $candidates as $c ) {
			$name      = $c['name'];
			$incoming  = $c['value'];
			$collision = $c['collision'];

			if ( ! array_key_exists( $name, $merged ) ) {
				$merged[ $name ]  = $incoming;
				$sources[ $name ] = array(
					'pkg'      => $c['pkg'],
					'priority' => $c['prio'],
					'isRoot'   => false,
				);
				continue;
			}

			$existing_origin = $sources[ $name ] ?? array(
				'isRoot'   => false,
				'priority' => 99999,
				'pkg'      => 'unknown',
			);
			if ( $existing_origin['isRoot'] ) {
				if ( 'chain' === $collision ) {
					$merged[ $name ] = $this->chain( $merged[ $name ], $incoming );
				}
				continue; // keep-root or replace-nonroot have no effect on root-owned entry.
			}

			// Existing is from another package.
			if ( 'chain' === $collision ) {
				$merged[ $name ] = $this->chain( $merged[ $name ], $incoming );
				continue;
			}

			if ( 'replace-nonroot' === $collision ) {
				if ( $c['prio'] < ( $existing_origin['priority'] ?? 100 ) ) {
					$merged[ $name ]  = $incoming;
					$sources[ $name ] = array(
						'pkg'      => $c['pkg'],
						'priority' => $c['prio'],
						'isRoot'   => false,
					);
				}
				continue;
			}
			// keep-root: keep first encountered non-root script according to sorting order.
		}

		$root->setScripts( $merged );
		$this->done = true;

		if ( $verbose ) {
			$this->io->write( '<info>shared-scripts: injection complete</info>' );
		}
	}

	/**
	 * Non-operation.
	 *
	 * @param Composer    $composer n/a.
	 * @param IOInterface $io n/a.
	 * @return void
	 */
	public function deactivate( Composer $composer, IOInterface $io ): void {
		// no-op.
	}

	/**
	 * Non-operation.
	 *
	 * @param Composer    $composer n/a.
	 * @param IOInterface $io n/a.
	 * @return void
	 */
	public function uninstall( Composer $composer, IOInterface $io ): void {
		// no-op.
	}

	/**
	 * Apply a prefix to a string.
	 *
	 * @param string $name string to prefix.
	 * @param string $prefix prefix to add.
	 * @param string $delimiter separator between prefix and string.
	 * @return string prefixed string.
	 */
	private function applyPrefix( string $name, string $prefix, string $delimiter ): string {
		$prefix = trim( $prefix );
		if ( '' === $prefix ) {
			return $name;
		}
		return $prefix . $delimiter . $name;
	}

	/**
	 * Checks if a given script name is valid.
	 *
	 * @param string $name name to check.
	 * @return boolean true if valid.
	 */
	private function isValidScriptName( string $name ): bool {
		// Safe superset of Composer script name chars.
		return (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/', $name );
	}

	/**
	 * Normalizes a script value.
	 *
	 * @param mixed $value script value to normalize.
	 * @return array|string|null  normalized script or null if invalid.
	 */
	private function normalizeScriptValue( $value ) {
		if ( is_string( $value ) && '' !== $value ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $v ) {
				if ( is_string( $v ) && ( '' !== $v ) ) {
					$out[] = $v;
				}
			}
			return array() === $out ? null : $out;
		}
		return null;
	}

	/**
	 * Chain together arrays of strings.
	 *
	 * @param array|string $a strings.
	 * @param array|string $b strings.
	 * @return array
	 */
	private function chain( $a, $b ): array {
		$aa = is_array( $a ) ? $a : array( $a );
		$bb = is_array( $b ) ? $b : array( $b );
		return array_values(
			array_filter(
				array_merge( $aa, $bb ),
				static fn( $x ) => is_string( $x ) && '' !== $x
			)
		);
	}

	/**
	 * Normalize a string or array of strings to a clean array of strings.
	 *
	 * @param mixed $v string or array to clean.
	 * @return string[]
	 */
	private function normalizeStringArray( $v ): array {
		$arr = array();
		if ( is_string( $v ) ) {
			$arr[] = $v;
		} elseif ( is_array( $v ) ) {
			foreach ( $v as $s ) {
				if ( is_string( $s ) && '' !== $s ) {
					$arr[] = $s;
				}
			}
		}
		return $arr;
	}

	/**
	 * Wildcard matcher independent of fnmatch (Windows-safe).
	 * Supports '*' and '?' like globbing. Anchored match.
	 *
	 * @param string $name name to check.
	 * @param array  $patterns pattern to match.
	 * @return boolean
	 */
	private function matchesAny( string $name, array $patterns ): bool {
		foreach ( $patterns as $p ) {
			$p     = (string) $p;
			$regex = $this->globToRegex( $p );
			if ( 1 === preg_match( $regex, $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Converts a glob pattern to a safe regex. '*' -> '.*', '?' -> '.', escape others.
	 *
	 * @param string $glob glob pattern.
	 * @return string
	 */
	private function globToRegex( string $glob ): string {
		$quoted = '';
		$len    = strlen( $glob );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $glob[ $i ];
			if ( '*' === $ch ) {
				$quoted .= '.*';
			} elseif ( '?' === $ch ) {
				$quoted .= '.';
			} else {
				$quoted .= preg_quote( $ch, '/' );
			}
		}
		return '/^' . $quoted . '$/i';
	}
}
