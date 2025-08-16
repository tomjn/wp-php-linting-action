<?php
declare(strict_types=1);

namespace Tomjn\SharedScripts;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Plugin\CommandEvent;

final class Plugin implements PluginInterface, EventSubscriberInterface {

	private Composer $composer;
	private IOInterface $io;
	private bool $done = false;

	public function activate( Composer $composer, IOInterface $io ): void {
		$this->composer = $composer;
		$this->io       = $io;
	}

	public static function getSubscribedEvents(): array {
		return array(
			PluginEvents::PRE_COMMAND_RUN => array( 'injectScripts', 100 ),
		);
	}

	public function injectScripts( CommandEvent $event ): void {
		if ( $this->done ) {
			return;
		}

		$cmd = $event->getCommandName();
		if ( $cmd !== 'run-script' && $cmd !== 'run' ) {
			return; // only affect composer run/run-script
		}

		$root     = $this->composer->getPackage();
		$rootName = $root->getName() ?: '__root__';

		// Root-level manager config
		$rootExtra = $root->getExtra();
		$mgr       = is_array( $rootExtra['shared-scripts'] ?? null ) ? $rootExtra['shared-scripts'] : array();

		// Default allow list: if unset, default to ["humanmade/*"]
		$allowPatterns = array_key_exists( 'allow', $mgr )
			? $this->normalizeStringArray( $mgr['allow'] )
			: array( 'humanmade/*' );

		$denyPatterns     = $this->normalizeStringArray( $mgr['deny'] ?? array() );
		$defaultCollision = (string) ( $mgr['default-collision'] ?? 'keep-root' ); // keep-root|chain|replace-nonroot
		$prefixes         = is_array( $mgr['prefixes'] ?? null ) ? $mgr['prefixes'] : array();
		$delimiter        = is_string( $mgr['delimiter'] ?? null ) && $mgr['delimiter'] !== '' ? (string) $mgr['delimiter'] : ':';
		$verbose          = (bool) ( $mgr['verbose'] ?? false );

		$merged  = $root->getScripts();
		$sources = array();
		foreach ( array_keys( $merged ) as $k ) {
			$sources[ $k ] = array(
				'pkg'      => $rootName,
				'priority' => PHP_INT_MIN,
				'isRoot'   => true,
			);
		}

		$repo = $this->composer->getRepositoryManager()->getLocalRepository();
		/** @var PackageInterface[] $packages */
		$packages = $repo->getPackages();

		$candidates = array();
		foreach ( $packages as $pkg ) {
			$pkgName = $pkg->getName();
			if ( $pkgName === $rootName ) {
				continue;
			}

			// deny overrides
			if ( $this->matchesAny( $pkgName, $denyPatterns ) ) {
				if ( $verbose ) {
					$this->io->write( "shared-scripts: denied $pkgName" );
				}
				continue;
			}
			// must match allow list
			if ( ! $this->matchesAny( $pkgName, $allowPatterns ) ) {
				if ( $verbose ) {
					$this->io->write( "shared-scripts: not allowed $pkgName" );
				}
				continue;
			}

			$extra = $pkg->getExtra();
			$conf  = $extra['shared-scripts'] ?? null;
			if ( ! is_array( $conf ) ) {
				continue; // only opt-in packages are considered
			}
			if ( isset( $conf['enabled'] ) && $conf['enabled'] === false ) {
				continue;
			}

			$scripts = $conf['scripts'] ?? null;
			if ( ! is_array( $scripts ) || $scripts === array() ) {
				continue;
			}

			$pkgPrefix    = (string) ( $prefixes[ $pkgName ] ?? ( $conf['prefix'] ?? '' ) );
			$pkgDelimiter = isset( $conf['delimiter'] ) && is_string( $conf['delimiter'] ) && $conf['delimiter'] !== '' ? (string) $conf['delimiter'] : $delimiter;
			$prio         = (int) ( $conf['priority'] ?? 100 );
			$collision    = (string) ( $conf['collision'] ?? $defaultCollision ); // keep-root|chain|replace-nonroot

			foreach ( $scripts as $name => $value ) {
				$finalName = $this->applyPrefix( (string) $name, $pkgPrefix, $pkgDelimiter );
				if ( ! $this->isValidScriptName( $finalName ) ) {
					if ( $verbose ) {
						$this->io->warning( "shared-scripts: skipped invalid script name '$finalName' from $pkgName" );
					}
					continue;
				}
				$norm = $this->normalizeScriptValue( $value );
				if ( $norm === null ) {
					if ( $verbose ) {
						$this->io->warning( "shared-scripts: skipped invalid script value for '$finalName' from $pkgName" );
					}
					continue;
				}
				$candidates[] = array(
					'name'      => $finalName,
					'value'     => $norm,
					'pkg'       => $pkgName,
					'prio'      => $prio,
					'collision' => $collision,
				);
			}
		}

		// Deterministic: priority asc, package name asc, script name asc
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

			$existingOrigin = $sources[ $name ] ?? array(
				'isRoot'   => false,
				'priority' => 99999,
				'pkg'      => 'unknown',
			);
			if ( $existingOrigin['isRoot'] ) {
				if ( $collision === 'chain' ) {
					$merged[ $name ] = $this->chain( $merged[ $name ], $incoming );
				}
				continue; // keep-root or replace-nonroot have no effect on root-owned entry
			}

			// Existing is from another package
			if ( $collision === 'chain' ) {
				$merged[ $name ] = $this->chain( $merged[ $name ], $incoming );
				continue;
			}
			if ( $collision === 'replace-nonroot' ) {
				if ( $c['prio'] < ( $existingOrigin['priority'] ?? 100 ) ) {
					$merged[ $name ]  = $incoming;
					$sources[ $name ] = array(
						'pkg'      => $c['pkg'],
						'priority' => $c['prio'],
						'isRoot'   => false,
					);
				}
				continue;
			}
			// keep-root: keep first encountered non-root script according to sorting order
		}

		$root->setScripts( $merged );
		$this->done = true;

		if ( $verbose ) {
			$this->io->write( '<info>shared-scripts: injection complete</info>' );
		}
	}

	public function deactivate( Composer $composer, IOInterface $io ): void {}
	public function uninstall( Composer $composer, IOInterface $io ): void {}

	private function applyPrefix( string $name, string $prefix, string $delimiter ): string {
		$prefix = trim( $prefix );
		if ( $prefix === '' ) {
			return $name;
		}
		return $prefix . $delimiter . $name;
	}

	private function isValidScriptName( string $name ): bool {
		// Safe superset of Composer script name chars
		return (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/', $name );
	}

	/**
	 * @param mixed $value
	 * @return array|string|null  normalized script or null if invalid
	 */
	private function normalizeScriptValue( $value ) {
		if ( is_string( $value ) && $value !== '' ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $v ) {
				if ( is_string( $v ) && $v !== '' ) {
					$out[] = $v;
				}
			}
			return $out === array() ? null : $out;
		}
		return null;
	}

	/**
	 * @param array|string $a
	 * @param array|string $b
	 * @return array
	 */
	private function chain( $a, $b ): array {
		$aa = is_array( $a ) ? $a : array( $a );
		$bb = is_array( $b ) ? $b : array( $b );
		return array_values( array_filter( array_merge( $aa, $bb ), static fn( $x ) => is_string( $x ) && $x !== '' ) );
	}

	/**
	 * Normalize a string or array of strings to a clean array of strings.
	 *
	 * @param mixed $v
	 * @return string[]
	 */
	private function normalizeStringArray( $v ): array {
		$arr = array();
		if ( is_string( $v ) ) {
			$arr[] = $v;
		} elseif ( is_array( $v ) ) {
			foreach ( $v as $s ) {
				if ( is_string( $s ) && $s !== '' ) {
					$arr[] = $s;
				}
			}
		}
		return $arr;
	}

	/**
	 * Wildcard matcher independent of fnmatch (Windows-safe).
	 * Supports '*' and '?' like globbing. Anchored match.
	 */
	private function matchesAny( string $name, array $patterns ): bool {
		foreach ( $patterns as $p ) {
			$p     = (string) $p;
			$regex = $this->globToRegex( $p );
			if ( preg_match( $regex, $name ) === 1 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Convert a glob pattern to a safe regex. '*' -> '.*', '?' -> '.', escape others.
	 */
	private function globToRegex( string $glob ): string {
		$quoted = '';
		$len    = strlen( $glob );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $glob[ $i ];
			if ( $ch === '*' ) {
				$quoted .= '.*';
			} elseif ( $ch === '?' ) {
				$quoted .= '.';
			} else {
				$quoted .= preg_quote( $ch, '/' );
			}
		}
		return '/^' . $quoted . '$/i';
	}
}
