<?php

namespace HalloWelt\MigrateEasyRedmineKnowledgebase\Composer;

use HalloWelt\MediaWiki\Lib\MediaWikiXML\Builder;
use HalloWelt\MediaWiki\Lib\Migration\ComposerBase;
use HalloWelt\MediaWiki\Lib\Migration\DataBuckets;
use HalloWelt\MediaWiki\Lib\Migration\IOutputAwareInterface;
use HalloWelt\MediaWiki\Lib\Migration\Workspace;
use Symfony\Component\Console\Output\Output;

class EasyRedmineKnowledgebaseComposer extends ComposerBase implements IOutputAwareInterface {

	/**
	 * @var DataBuckets
	 */
	private $dataBuckets;

	/**
	 * @var Output
	 */
	private $output = null;

	/**
	 * @param array $config
	 * @param Workspace $workspace
	 * @param DataBuckets $buckets
	 */
	public function __construct( $config, Workspace $workspace, DataBuckets $buckets ) {
		parent::__construct( $config, $workspace, $buckets );

		$this->dataBuckets = new DataBuckets( [
			'body-contents-to-pages-map',
			'title-attachments',
			'title-revisions',
			'files'
		] );

		$this->customBuckets = new DataBuckets( [
			'title-uploads',
			'title-uploads-fail'
		] );

		$this->dataBuckets->loadFromWorkspace( $this->workspace );
	}

	/**
	 * @param Output $output
	 */
	public function setOutput( Output $output ) {
		$this->output = $output;
	}

	/**
	 * @param Builder $builder
	 * @return void
	 */
	public function buildXML( Builder $builder ) {
		throw new \Exception( "Implement me" );
	}
}
