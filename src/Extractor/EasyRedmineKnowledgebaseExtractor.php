<?php

namespace HalloWelt\MigrateEasyRedmineKnowledgebase\Extractor;

use HalloWelt\MediaWiki\Lib\Migration\DataBuckets;
use HalloWelt\MediaWiki\Lib\Migration\ExtractorBase;
use HalloWelt\MediaWiki\Lib\Migration\Workspace;
use SplFileInfo;

class EasyRedmineKnowledgebaseExtractor extends ExtractorBase {
	/**
	 * @param array $config
	 * @param Workspace $workspace
	 * @param DataBuckets $buckets
	 */
	public function __construct( $config, Workspace $workspace, DataBuckets $buckets ) {
		$this->config = $config;
		$this->workspace = $workspace;
		$this->buckets = $buckets;
		$this->analyzeBuckets = new DataBuckets( [
			'attachment-files',
		] );
		$this->analyzeBuckets->loadFromWorkspace( $workspace );
	}

	/**
	 * @param SplFileInfo $sourceDir
	 * @return bool
	 */
	protected function doExtract( SplFileInfo $sourceDir ): bool {
		$attachments = $this->analyzeBuckets->getBucketData( 'attachment-files' );
		foreach ( $attachments as $attachment ) {
			foreach ( $attachment as $file ) {
				$sourcePath = $sourceDir->getPathname() . '/' . $file['source_path'];
				if ( !is_file( $sourcePath ) ) {
					print_r( "File not found: " . $sourcePath . "\n" );
					continue;
				}
				$targetPath = $this->workspace->saveUploadFile(
					$file['target_path'],
					file_get_contents( $sourcePath )
				);
			}
		}
		return true;
	}
}
