<?php

namespace HalloWelt\MigrateEasyRedmineKnowledgebase\Converter;

use HalloWelt\MediaWiki\Lib\Migration\Converter\PandocHTML;
use HalloWelt\MediaWiki\Lib\Migration\DataBuckets;
use HalloWelt\MediaWiki\Lib\Migration\IOutputAwareInterface;
use HalloWelt\MediaWiki\Lib\Migration\Workspace;
use SplFileInfo;
use Symfony\Component\Console\Output\Output;

class EasyRedmineKnowledgebaseConverter extends PandocHTML implements IOutputAwareInterface {

	/** @var DataBuckets */
	private $dataBuckets = null;
	/** @var string */
	private $wikiText = '';
	/** @var Output */
	private $output = null;

	/** @var string */
	private $mainpage = 'Main Page';

	/**
	 * @param array $config
	 * @param Workspace $workspace
	 */
	public function __construct( $config, Workspace $workspace ) {
		parent::__construct( $config, $workspace );

		$this->dataBuckets = new DataBuckets( [
			'pages-ids-to-titles-map',
			'pages-titles-map',
			'title-attachments',
			'body-contents-to-pages-map',
			'page-id-to-space-id',
			'filenames-to-filetitles-map',
			'title-metadata',
			'attachment-orig-filename-target-filename-map',
			'files'
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
	 * @inheritDoc
	 */
	protected function doConvert( SplFileInfo $file ): string {
		$this->output->writeln( $file->getPathname() );
		throw new \Exception( "Implement me" );
		$this->wikiText = parent::doConvert( $file );
		return $this->wikiText;
	}
}
