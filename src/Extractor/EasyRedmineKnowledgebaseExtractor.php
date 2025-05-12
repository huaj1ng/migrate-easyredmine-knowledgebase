<?php

namespace HalloWelt\MigrateEasyRedmineKnowledgebase\Extractor;

use HalloWelt\MediaWiki\Lib\Migration\IExtractor;
use HalloWelt\MigrateEasyRedmineKnowledgebase\SimpleHandler;
use SplFileInfo;

class EasyRedmineKnowledgebaseExtractor extends SimpleHandler implements IExtractor {

	/** @var array */
	protected $dataBucketList = [
		'attachment-files',
		'diagram-contents',
	];

	/**
	 * @param SplFileInfo $sourceDir
	 * @return bool
	 */
	public function extract( SplFileInfo $sourceDir ): bool {
		$attachments = $this->dataBuckets->getBucketData( 'attachment-files' );
		if ( is_array( $attachments ) ) {
			foreach ( $attachments as $attachment ) {
				foreach ( $attachment as $file ) {
					$sourcePath = $sourceDir->getPathname() . '/' . $file['source_path'];
					if ( !is_file( $sourcePath ) ) {
						print_r( "File not found: " . $sourcePath . "\n" );
						continue;
					}
					$targetPath = $this->workspace->saveUploadFile(
						$file['target_filename'],
						file_get_contents( $sourcePath )
					);
				}
			}
		}
		$diagrams = $this->dataBuckets->getBucketData( 'diagram-contents' );
		if ( is_array( $diagrams ) ) {
			foreach ( $diagrams as $diagram ) {
				$targetPath = $this->workspace->saveUploadFile(
					$diagram['target_filename'],
					base64_decode( $diagram['data_base64'] )
				);
			}
		}
		return true;
	}
}
