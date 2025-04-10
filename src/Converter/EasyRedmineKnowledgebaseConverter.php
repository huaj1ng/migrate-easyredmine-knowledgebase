<?php

namespace HalloWelt\MigrateEasyRedmineKnowledgebase\Converter;

use HalloWelt\MigrateEasyRedmineKnowledgebase\SimpleHandler;
use HalloWelt\MigrateEasyRedmineKnowledgebase\Utility\ConvertToolbox;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;

class EasyRedmineKnowledgebaseConverter extends SimpleHandler {

	/** @var array */
	protected $dataBucketList = [
		'wiki-pages',
		'page-revisions',
	];

	/** @var ConvertToolbox */
	protected $toolbox = null;

	/**
	 * @return bool
	 */
	public function convert(): bool {
		$this->toolbox = new ConvertToolbox( $this->workspace );
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
				$content = $this->preprocess( $content );
				// $content = $this->processWithPandoc( $content, 'html', 'textile' );
				// $content = $this->processWithPandoc( $content, 'textile', 'html' );
				// $content = $this->processWithPandoc( $content, 'html', 'mediawiki' );
				$content = $this->processWithPandoc( $content, 'textile', 'mediawiki' );
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
	 */
	public function preprocess( $content ) {
		$content = $this->toolbox->replaceCustomized( $content );
		// $content = $this->toolbox->replaceInlineBeforePandoc( $content );
		return $content;
	}

	/**
	 * @param string $content
	 * @param string $source
	 * @param string $target
	 * @return string
	 * @phpcs:disable MediaWiki.Usage.ForbiddenFunctions.proc_open
	 */
	public function processWithPandoc( $content, $source, $target ) {
		$process = proc_open(
			"pandoc --from $source --to $target",
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
				. "conversion from $source to $target skipped </error>"
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
		$content = $chunks[0];
		if ( count( $chunks ) > 1 ) {
			for ( $i = 1; $i < count( $chunks ); $i++ ) {
				$chunk = $chunks[$i];
				$parts = explode( "</pre>", $chunk, 2 );
				if ( count( $parts ) === 2 ) {
					$content .= $this->toolbox->convertCodeBlocks( $parts[0] );
					$content .= $this->postprocess( $parts[1] );
				} else {
					$content .= "<pre>" . $chunk;
				}
			}
		}
		return $content;
	}

	/**
	 * @param string $content
	 * @return string
	 */
	public function postprocess( $content ) {
		$content = $this->toolbox->replaceEncodedEntities( $content );
		$content = $this->toolbox->replaceEncodedEntities( $content );
		$content = $this->toolbox->replaceInlineTitles(
			'attachment:”',
			'”',
			'[[',
			']]',
			$content
		);
		$content = $this->handleHTMLTables( $content );
		$content = $this->handleImages( $content );
		$content = $this->handleAnchors( $content );
		return $content;
	}

	/**
	 * @param string $content
	 * @return string
	 */
	public function handleHTMLTables( $content ) {
		$chunks = explode( '<figure class="table">', $content );
		$content = $chunks[0];
		if ( count( $chunks ) > 1 ) {
			for ( $i = 1; $i < count( $chunks ); $i++ ) {
				$chunk = $chunks[$i];
				$parts = explode( "</figure>", $chunk, 2 );
				if ( count( $parts ) === 2 ) {
					$table = $this->processWithPandoc( $parts[0], 'html', 'mediawiki' );
					$table = preg_replace( '/\| \*/', "|\n*", $table );
					$content .= $table . $parts[1];
				} else {
					$content .= "<figure>" . $chunk;
				}
			}
		}
		$chunks = explode( '<table', $content );
		$content = $chunks[0];
		if ( count( $chunks ) > 1 ) {
			for ( $i = 1; $i < count( $chunks ); $i++ ) {
				$chunk = $chunks[$i];
				$parts = explode( ">", $chunk, 2 );
				if ( count( $parts ) === 2 ) {
					$pieces = explode( "</table>", $parts[1], 2 );
					$table = $this->processWithPandoc(
						"<table" . $parts[0] . ">" . $pieces[0] . "</table>",
						'html',
						'mediawiki'
					);
					$content .= preg_replace( '/\| \*/', "|\n*", $table );
					$content .= isset( $pieces[1] ) ? $pieces[1] : '';
				} else {
					$content .= "<table" . $chunk;
				}
			}
		}
		return $content;
	}

	/**
	 * @param string $content
	 * @return string
	 */
	public function handleImages( $content ) {
		$chunks = explode( '<figure class="image', $content );
		$content = $chunks[0];
		if ( count( $chunks ) > 1 ) {
			for ( $i = 1; $i < count( $chunks ); $i++ ) {
				$chunk = $chunks[$i];
				$parts = explode( ">", $chunk, 2 );
				if ( count( $parts ) === 2 ) {
					$pieces = explode( "</figure>", $parts[1], 2 );
					// $content .= "<!--<figure" . $parts[0] . ">-->";
					// $content .= $pieces[0] . "<!--</figure>-->";
					$content .= $pieces[0];
					$content .= isset( $pieces[1] ) ? $pieces[1] : '';
				} else {
					$content .= "<figure" . $chunk;
				}
			}
		}
		return $content;
	}

	/**
	 * @param string $content
	 * @return string
	 */
	public function handleAnchors( $content ) {
		$chunks = explode( '<a ', $content );
		$content = $chunks[0];
		if ( count( $chunks ) > 1 ) {
			for ( $i = 1; $i < count( $chunks ); $i++ ) {
				$chunk = $chunks[$i];
				$parts = explode( '>', $chunk, 2 );
				$match = preg_match( '/href="([^"]+)"/', $parts[0], $matches );
				if ( $match ) {
					$link = $matches[1];
				} else {
					$link = "anchor-handle-error";
					print_r( "\nError occured when handling anchor href in: " . $chunk );
				}
				// point to check if the link is a local link: tba
				if ( count( $parts ) === 2 ) {
					$pieces = explode( "</a>", $parts[1], 2 );
					if ( !isset( $pieces[0] ) ) {
						$text = $link;
					} else {
						$text = preg_replace( '/<br \/>/', '', $pieces[0] );
						$text = preg_replace( '/\n/', '', $text );
						$text = preg_replace( '/\[\[/', '', $text );
						$text = preg_replace( '/\]\]/', '', $text );
						$text = trim( $text );
						if ( $text === '' ) {
							$text = $link;
						}
					}
					$content .= "[" . $link . " " . $text . "]";
					$content .= isset( $pieces[1] ) ? $pieces[1] : '';
				} else {
					$content .= "<a" . $chunk;
					print_r( "\nError occured when handling anchor in: " . $chunk );
				}
			}
		}
		return $content;
	}
}
