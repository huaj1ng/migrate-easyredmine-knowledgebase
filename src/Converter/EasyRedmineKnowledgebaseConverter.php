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
}
