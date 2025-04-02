<?php

namespace HalloWelt\MigrateEasyRedmineKnowledgebase\Converter;

use HalloWelt\MigrateEasyRedmineKnowledgebase\SimpleHandler;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;

class EasyRedmineKnowledgebaseConverter extends SimpleHandler {

	/** @var array */
	protected $dataBucketList = [
		'wiki-pages',
		'page-revisions',
		'customizations',
	];

	/**
	 * @return bool
	 */
	public function convert(): bool {
		$wikiPages = $this->dataBuckets->getBucketData( 'wiki-pages' );
		$pageRevisions = $this->dataBuckets->getBucketData( 'page-revisions' );

		$totalPages = count( $wikiPages );
		$output = new ConsoleOutput();
		$progressBar = new ProgressBar( $output, $totalPages );
		$progressBar->setFormat( ' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%' );
		$progressBar->start();

		foreach ( $wikiPages as $id => $page ) {
			$result = [];
			foreach ( $pageRevisions[$id] as $version => $revision ) {
				$content = $revision['data'];
				$content = $this->textileToMediaWiki( $content );
				$content = $this->handlePreTags( $content );
				$result[$version] = $content;
			}
			$this->buckets->addData( 'revision-wikitext', $id, $result, false, false );
			$progressBar->advance();
		}
		$progressBar->finish();
		$output->writeln( "\n" );
		return true;
	}

	/**
	 * @param string $content
	 * @return string
	 * @phpcs:disable MediaWiki.Usage.ForbiddenFunctions.proc_open
	 */
	public function textileToMediaWiki( $content ) {
		$process = proc_open(
			"pandoc --from textile --to mediawiki",
			[
				0 => [ 'pipe', 'r' ],
				1 => [ 'pipe', 'w' ],
				2 => [ 'pipe', 'w' ],
			],
			$pipes
		);
		if ( $process === false ) {
			$this->output->writeln(
				"<error>Failed to start Pandoc process, "
				. "textileToMediaWiki conversion skipped </error>"
			);
			return $content;
		}
		fwrite( $pipes[0], $content );
		fclose( $pipes[0] );
		$converted = stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		$errors = stream_get_contents( $pipes[2] );
		fclose( $pipes[2] );
		$exitCode = proc_close( $process );
		if ( $exitCode !== 0 ) {
			$this->output->writeln(
				"<error>Pandoc conversion failed with exit code $exitCode: $errors, "
				. "textileToMediaWiki conversion skipped </error>"
			);
			return $content;
		}
		return $converted;
	}

	/**
	 * @param string $content
	 * @return string
	 */
	public function handlePreTags( $content ) {
		$chunks = explode( "<pre>", $content );
		$chunks[0] = $this->postprocess( $chunks[0] );
		$result = $chunks[0];
		if ( count( $chunks ) > 1 ) {
			for ( $i = 1; $i < count( $chunks ); $i++ ) {
				$chunk = $chunks[$i];
				$parts = explode( "</pre>", $chunk, 2 );
				if ( count( $parts ) === 2 ) {
					$result .= $this->convertCodeBlocks( $parts[0] );
					$result .= $this->postprocess( $parts[1] );
				} else {
					$result .= "<pre>" . $chunk;
				}
			}
		}
		return $result;
	}

	/**
	 * Converts HTML-encoded code blocks to MediaWiki syntax highlighting
	 *
	 * @param string $content Content inside <pre> tags
	 * @return string
	 */
	private function convertCodeBlocks( $content ) {
		$encodedEntities = [
			'&lt;' => '<',
			'&gt;' => '>',
			'&amp;' => '&',
			'&quot;' => '"',
			'&amp;lt;' => '<',
			'&amp;gt;' => '>',
			'&amp;amp;' => '&',
			'&amp;quot;' => '"',
		];
		$content = str_replace( array_keys( $encodedEntities ), array_values( $encodedEntities ), $content );
		$codeSpanPattern = '/<span\s+class="[a-z0-9]+">(.*?)<\/span>/i';
		$content = preg_replace_callback( $codeSpanPattern, static function ( $matches ) {
			return $matches[1];
		}, $content );
		if ( preg_match( '/<code\s+class="([^"]+)">/', $content, $matches ) ) {
			$language = $matches[1];
			$content = preg_replace( '/<code\s+class="([^"]+)">/', '', $content );
			$content = preg_replace( '/<\/code>$/', '', $content );
			// need further correspondence of language tags
			$content = "<syntaxhighlight lang=\"$language\">\n" . $content . "\n</syntaxhighlight>";
		} elseif ( preg_match( '/<code>/', $content ) ) {
			$content = preg_replace( '/<code>/', '', $content );
			$content = preg_replace( '/<code>/', '', $content );
			$content = "<pre>" . $content . "</pre>";
		} else {
			$content = "<pre>" . $content . "</pre>";
		}
		$content = str_replace( array_keys( $encodedEntities ), array_values( $encodedEntities ), $content );
		return $content;
	}

	/**
	 * @param string $content
	 * @return string
	 */
	public function postprocess( $content ) {
		$content = $this->replaceInlineTitles(
			'attachment:”',
			'”',
			'[[',
			']]',
			$content
		);
		return $content;
	}

	/**
	 * @param string $oldStart
	 * @param string $oldEnd
	 * @param string $newStart
	 * @param string $newEnd
	 * @param string $content
	 * @return string
	 */
	public function replaceInlineTitles( $oldStart, $oldEnd, $newStart, $newEnd, $content ) {
		$lines = explode( "\n", $content );
		foreach ( $lines as $index => $line ) {
			$parts = explode( $oldStart, $line );
			if ( count( $parts ) > 1 ) {
				$newLine = $parts[0];
				for ( $i = 1; $i < count( $parts ); $i++ ) {
					$part = $parts[$i];
					$pos = strpos( $part, $oldEnd );
					if ( $pos !== false ) {
						$title = substr( $part, 0, $pos );
						$remainder = substr( $part, $pos + strlen( $oldEnd ) );
						$newLine .= $newStart . $this->getFormattedTitle( $title ) . $newEnd . $remainder;
					} else {
						$newLine .= $oldStart . $part;
					}
				}
				$lines[$index] = $newLine;
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * @param string $title
	 * @return string
	 */
	public function getFormattedTitle( $title ) {
		$wikiPages = $this->dataBuckets->getBucketData( 'wiki-pages' );
		$foundKey = array_key_first( array_filter( $wikiPages, static function ( $page ) use ( $title ) {
			return isset( $page['title'] ) && $page['title'] === $title;
		} ) );
		if ( $foundKey !== null ) {
			return $wikiPages[$foundKey]['formatted_title'];
		}

		if ( substr( $title, -4 ) === '.png' ) {
			$baseName = substr( $title, 0, -4 );
			$foundKey = array_key_first( array_filter( $wikiPages, static function ( $page ) use ( $baseName ) {
				return isset( $page['title'] ) && strpos( $page['title'], $baseName ) === 0;
			} ) );
			if ( $foundKey !== null ) {
				return $wikiPages[$foundKey]['formatted_title'];
			}
		}

		$customizations = $this->dataBuckets->getBucketData( 'customizations' );
		if ( !isset( $customizations['is-enabled'] ) || $customizations['is-enabled'] !== true ) {
			print_r( "\nNo customization enabled\n" );
			$customizations = [];
			$customizations['is-enabled'] = false;
		} else {
			print_r( "\nCustomizations loaded\n" );
		}
		if ( isset( $customizations['title-cheatsheet'][$title] ) ) {
			return $customizations['title-cheatsheet'][$title];
		}

		$this->output->writeln( "<error>Original title '$title' not found </error>" );
		return $title;
	}
}
