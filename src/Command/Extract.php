<?php

namespace HalloWelt\MigrateEasyRedmineKnowledgebase\Command;

use Exception;
use HalloWelt\MediaWiki\Lib\Migration\DataBuckets;
use HalloWelt\MediaWiki\Lib\Migration\IExtractor;
use HalloWelt\MediaWiki\Lib\Migration\IOutputAwareInterface;
use HalloWelt\MediaWiki\Lib\Migration\Workspace;
use HalloWelt\MigrateEasyRedmineKnowledgebase\ISourcePathAwareInterface;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input;
use Symfony\Component\Console\Output\OutputInterface;

class Extract extends Command {

	/** @var array */
	protected $config = [];

	/** @var Input\InputInterface */
	protected $input = null;

	/** @var OutputInterface */
	protected $output = null;

	/** @var string */
	protected $src = '';

	/** @var string */
	protected $dest = '';

	/** @param array $config */
	public function __construct( $config ) {
		parent::__construct();
		$this->config = $config;
	}

	protected function configure() {
		$this->setName( 'extract' )->setDescription(
			'Extract all wanted attachments into the workspace'
		);
		$this->setDefinition( new Input\InputDefinition( [
			new Input\InputOption(
				'src',
				null,
				Input\InputOption::VALUE_REQUIRED,
				'Specifies the path to the input directory'
			),
			new Input\InputOption(
				'dest',
				null,
				Input\InputOption::VALUE_OPTIONAL,
				'Specifies the path to the output directory',
				'.'
			)
		] ) );
		return parent::configure();
	}

	/**
	 * @param Input\InputInterface $input
	 * @param OutputInterface $output
	 * @return int
	 */
	protected function execute( Input\InputInterface $input, OutputInterface $output ): int {
		$this->input = $input;
		$this->output = $output;
		$this->src = realpath( $this->input->getOption( 'src' ) );
		$this->dest = realpath( $this->input->getOption( 'dest' ) );

		$this->output->writeln( "Source: {$this->src}" );
		$this->output->writeln( "Destination: {$this->dest}\n" );
		$returnValue = $this->doExtract();
		$this->output->writeln( '<info>Done.</info>' );
		return $returnValue;
	}

	protected function doExtract(): bool {
		if ( !is_dir( $this->src ) || !is_dir( $this->dest ) ) {
			throw new Exception( "Both source and destination path must be valid directories" );
		}
		$sourceDir = new SplFileInfo( $this->src );
		$workspaceDir = new SplFileInfo( $this->dest );
		$workspace = new Workspace( $workspaceDir );
		$buckets = new DataBuckets( [] );
		$buckets->loadFromWorkspace( $workspace );

		$extractorFactoryCallbacks = $this->config['extractors'];
		foreach ( $extractorFactoryCallbacks as $key => $callback ) {
			$extractor = call_user_func_array(
				$callback,
				[ $this->config, $workspace, $buckets ]
			);
			if ( $extractor instanceof IExtractor === false ) {
				throw new Exception(
					"Factory callback for extractor '$key' did not return an "
					. "IExtractor object"
				);
			}
			if ( $extractor instanceof IOutputAwareInterface ) {
				$extractor->setOutput( $this->output );
			}
			if ( $extractor instanceof ISourcePathAwareInterface ) {
				$extractor->setSourcePath( $this->src );
			}

			$result = $extractor->extract( $sourceDir );
			if ( $result === false ) {
				$this->output->writeln( "<error>Extractor '$key' failed.</error>" );
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array $config
	 * @return Extract
	 */
	public static function factory( $config ): Extract {
		return new static( $config );
	}
}
