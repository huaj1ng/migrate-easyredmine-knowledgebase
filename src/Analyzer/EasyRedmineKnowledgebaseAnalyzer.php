<?php

namespace HalloWelt\MigrateEasyRedmineKnowledgebase\Analyzer;

use HalloWelt\MediaWiki\Lib\Migration\Analyzer\SqlBase;
use HalloWelt\MediaWiki\Lib\Migration\DataBuckets;
use HalloWelt\MediaWiki\Lib\Migration\IAnalyzer;
use HalloWelt\MediaWiki\Lib\Migration\IOutputAwareInterface;
use HalloWelt\MediaWiki\Lib\Migration\SqlConnection;
use HalloWelt\MediaWiki\Lib\Migration\TitleBuilder;
use HalloWelt\MediaWiki\Lib\Migration\Workspace;
use SplFileInfo;
use Symfony\Component\Console\Output\Output;

class EasyRedmineKnowledgebaseAnalyzer extends SqlBase implements
	IAnalyzer,
	IOutputAwareInterface
{
	/** @var Output */
	private $output = null;

	/** @var DataBuckets */
	private $customBuckets = null;

	/** @var array */
	private $customizations = [];

	/** @var array */
	private $userNames = [];

	/** @var array */
	private $diagramIds = [];

	/** @var array */
	private $wantedAttachmentRevisions = [];

	/** @var array */
	private $wantedAttachmentIds = [];

	/** @var int */
	private $maintenanceUserID = 1;

	private const INT_MAX = 2147483647;

	private const EKB_CAT_OFFSET = 1500000000;

	/**
	 *
	 * @param array $config
	 * @param Workspace $workspace
	 * @param DataBuckets $buckets
	 */
	public function __construct( $config, Workspace $workspace, DataBuckets $buckets ) {
		$this->config = $config;
		$this->workspace = $workspace;
		$this->buckets = $buckets;
		$this->customBuckets = new DataBuckets( [
			'customizations',
		] );
		$this->customBuckets->loadFromWorkspace( $this->workspace );
		$customizations = $this->customBuckets->getBucketData( 'customizations' );
		if ( !isset( $customizations['is-enabled'] ) || $customizations['is-enabled'] !== true ) {
			print_r( "No customization enabled\n" );
			$customizations = [];
			$customizations['is-enabled'] = false;
		} else {
			print_r( "Customizations loaded\n" );
		}
		$this->customizations = $customizations;
	}

	/**
	 * @param Output $output
	 */
	public function setOutput( Output $output ) {
		$this->output = $output;
	}

	/**
	 * @param SqlConnection $connection
	 * @return void
	 */
	protected function setNames( $connection ) {
		$res = $connection->query(
			"SELECT id, login, firstname, lastname FROM users;"
		);
		foreach ( $res as $row ) {
			$fullName = trim( $row['firstname'] . ' ' . $row['lastname'] );
			$this->userNames[$row['id']] = $row['login']
				? $row['login']
				: ( strlen( $fullName ) > 0 ? $fullName : 'User ' . $row['id'] );
		}
	}

	/**
	 * @param int $id
	 * @return string
	 */
	protected function getUserName( $id ) {
		if ( isset( $this->userNames[$id] ) ) {
			return $this->userNames[$id];
		}
		print_r( "User ID " . $id . " not found in userNames\n" );
		return $id;
	}

	/**
	 *
	 * @param SplFileInfo $file
	 * @return bool
	 */
	protected function doAnalyze( SplFileInfo $file ): bool {
		if ( $file->getFilename() !== 'connection.json' ) {
			print_r( "Please use a connection.json!" );
			return true;
		}
		$connection = new SqlConnection( $file );
		$this->setNames( $connection );
		$this->analyzeCategories( $connection );
		$this->analyzePages( $connection );
		$this->analyzeRevisions( $connection );
		$this->analyzeDiagrams( $connection );
		$this->analyzeAttachments( $connection );
		$this->doStatistics( $connection );
		return true;
	}

	/**
	 * Analyze existing story categories, generate pages and revisions
	 *
	 * @param SqlConnection $connection
	 */
	protected function analyzeCategories( $connection ) {
		$res = $connection->query(
			"SELECT c.id AS category_id, c.name AS title, c.parent_id, "
			. "c.description AS data, c.author_id, c.updated_on "
			. "FROM easy_knowledge_categories c;"
		);
		$rows = [];
		foreach ( $res as $row ) {
			$rows[$row['category_id']] = $row;
			unset( $rows[$row['category_id']]['category_id'] );
		}
		foreach ( array_keys( $rows ) as $page_id ) {
			$titleBuilder = new TitleBuilder( [] );
			// the migrated pages for categories should go to the Category namespace
			$builder = $titleBuilder->setNamespace( 14 );
			$page = $page_id;
			while ( true ) {
				$row = $rows[$page];
				if ( $row['parent_id'] === null ) {
					$builder = $builder->appendTitleSegment( $row['title'] );
					break;
				}
				$builder = $builder->appendTitleSegment( $row['title'] );
				$page = $row['parent_id'];
			}
			// naming convention: Category:<root_cat>/<sub_cat>/..
			$rows[$page_id]['formatted_title'] = $builder->invertTitleSegments()->build();
			$rows[$page_id]['version'] = 1;
			$rows[$page_id]['categories'] = [];
			$dummyId = $page_id + self::EKB_CAT_OFFSET;
			$pageRevision[1] = [
				'rev_id' => $dummyId,
				'page_id' => $dummyId,
				'author_id' => $rows[$page_id]['author_id'],
				'author_name' => $this->getUserName( $rows[$page_id]['author_id'] ),
				'comments' => '',
				'updated_on' => $rows[$page_id]['updated_on'],
				'parent_rev_id' => null,
				'data' => $rows[$page_id]['data'],
			];
			unset( $rows[$page_id]['data'] );
			unset( $rows[$page_id]['author_id'] );
			unset( $rows[$page_id]['updated_on'] );
			$this->buckets->addData( 'wiki-pages', $dummyId, $rows[$page_id], false, false );
			$this->buckets->addData( 'page-revisions', $dummyId, $pageRevision, false, false );
		}
	}

	/**
	 * Analyze existing story pages and generate info wiki-pages array
	 *
	 * @param SqlConnection $connection
	 */
	protected function analyzePages( $connection ) {
		$customizations = $this->customizations;
		$wikiPages = $this->buckets->getBucketData( 'wiki-pages' );
		$res = $connection->query(
			"SELECT s.id AS page_id, s.name AS title, s.version "
			. "FROM easy_knowledge_stories s;"
		);
		$rows = [];
		foreach ( $res as $row ) {
			$rows[$row['page_id']] = $row;
			unset( $rows[$row['page_id']]['page_id'] );
		}
		foreach ( array_keys( $rows ) as $page_id ) {
			$rows[$page_id]['categories'] = [];
			$res = $connection->query(
				"SELECT category_id "
				. "FROM easy_knowledge_story_categories sc "
				. "WHERE sc.story_id = $page_id;"
			);
			foreach ( $res as $row ) {
				$id = $row['category_id'] + self::EKB_CAT_OFFSET;
				$category = $wikiPages[$id]['formatted_title'];
				$category = str_replace( 'Category:', '', $category );
				$category = str_replace( '_', ' ', $category );
				$rows[$page_id]['categories'][] = $category;
			}
			$titleBuilder = new TitleBuilder( [] );
			// assume that the migrated pages go to the default namespace
			$rows[$page_id]['formatted_title'] = $titleBuilder->setNamespace( 0 )
				->appendTitleSegment( $rows[$page_id]['title'] )
				->build();
			// and all pages are imported as root pages
			$rows[$page_id]['parent_id'] = null;

			$fTitle = $rows[$page_id]['formatted_title'];
			if ( $customizations['is-enabled'] && isset( $customizations['categories-to-add'][$fTitle] ) ) {
				$rows[$page_id]['categories'] = array_unique( array_merge(
					$rows[$page_id]['categories'],
					$customizations['categories-to-add'][$fTitle]
				) );
			}
			if ( $customizations['is-enabled'] && isset( $customizations['pages-to-modify'][$fTitle] ) ) {
				if ( $customizations['pages-to-modify'][$fTitle] === false ) {
					continue;
				} else {
					$rows[$page_id]['formatted_title'] = $customizations['pages-to-modify'][$fTitle];
				}
			}
			$this->buckets->addData( 'wiki-pages', $page_id, $rows[$page_id], false, false );
		}
		// Page titles starting with "µ" are converted to capital "Μ" but not "M" in MediaWiki
	}

	/**
	 * Analyze revisions of story pages
	 *
	 * @param SqlConnection $connection
	 */
	protected function analyzeRevisions( $connection ) {
		$customizations = $this->customizations;
		$wikiPages = $this->buckets->getBucketData( 'wiki-pages' );
		foreach ( array_keys( $wikiPages ) as $page_id ) {
			$res = $connection->query(
				"SELECT sv.id AS rev_id, "
				. "sv.easy_knowledge_story_id AS page_id, "
				. "sv.author_id, sv.description AS data, "
				. "sv.updated_on, sv.version "
				. "FROM easy_knowledge_story_versions sv "
				. "WHERE easy_knowledge_story_id = " . $page_id . " "
				. "ORDER BY sv.version;"
			);
			// ORDER BY sv.version is ascending by default, which is important
			$rows = [];
			$last_ver = null;
			foreach ( $res as $row ) {
				$ver = $row['version'];
				if ( $customizations['is-enabled'] && $customizations['current-revision-only'] ) {
					if ( $ver != $wikiPages[$page_id]['version'] ) {
						continue;
					}
				}
				$rows[$ver] = $row;
				unset( $rows[$ver]['version'] );
				$rows[$ver]['parent_rev_id'] = ( $last_ver !== null ) ?
					$rows[$last_ver]['rev_id']
					: null;
				$last_ver = $ver;
				$rows[$ver]['author_name'] = $this->getUserName( $row['author_id'] );
				$rows[$ver]['comments'] = '';
				$this->scanData( $rows[$ver]['data'] );
				if (
					is_array( $wikiPages[$page_id]['categories'] )
					&& count( $wikiPages[$page_id]['categories'] ) > 0
				) {
					$rows[$ver]['data'] .= "\n[[Category:"
						. implode( "]]\n[[Category:", $wikiPages[$page_id]['categories'] )
						. "]]";
				}
			}
			if ( count( $rows ) !== 0 ) {
				$this->buckets->addData( 'page-revisions', $page_id, $rows, false, false );
			}
		}
	}

	/**
	 * Scan and ananlyze revision text data
	 *
	 * @param string $data
	 * @return void
	 */
	protected function scanData( $data ) {
		preg_match_all( '/{{include_diagram\((\d+)(?:--.+?)\)}}/', $data, $matches );
		if ( !empty( $matches[1] ) ) {
			$this->diagramIds = array_unique( array_merge( $this->diagramIds, $matches[1] ) );
		}
		$customizations = $this->customizations;
		if ( $customizations['is-enabled'] && isset( $customizations['redmine-domain'] ) ) {
			$domain = $customizations['redmine-domain'];
			$pattern = '/https?:\/\/' . preg_quote( $domain, '/' ) . '\/attachments\/';
			$pattern .= '(?:(?:download|thumbnail)\/)?';
			$pattern .= '(\d+)';
			$pattern .= '([^"\'\s>\xa0]*)/u';
			preg_match_all( $pattern, $data, $matches, PREG_SET_ORDER );
			foreach ( $matches as $match ) {
				if ( !isset( $match[1] ) ) {
					continue;
				}
				$attachmentId = (int)$match[1];
				$urlSuffix = isset( $match[2] ) ? $match[2] : '';
				$isVersionSpecific = strpos( $urlSuffix, 'version=true' ) !== false;
				if ( $isVersionSpecific ) {
					$this->wantedAttachmentRevisions[] = $attachmentId;
				} else {
					$this->wantedAttachmentIds[] = $attachmentId;
				}
			}
		}
	}

	/**
	 * Analyze current version of diagrams
	 *
	 * @param SqlConnection $connection
	 */
	protected function analyzeDiagrams( $connection ) {
		if ( count( $this->diagramIds ) === 0 ) {
			return;
		}
		$res = $connection->query(
			"SELECT d.id , d.title, d.current_position, d.project_id, "
			. "d.author_id, d.updated_at, d.html, d.xml_png FROM diagrams d "
			. "where d.id IN (" . implode( ", ", $this->diagramIds ) . ");"
		);
		foreach ( $res as $row ) {
			$id = $row['id'];
			unset( $row['id'] );
			$targetFilename = "Diagram" . $id . "--" . $row['title'] . '.png';
			$row['target_filename'] = $targetFilename;
			$titleBuilder = new TitleBuilder( [] );
			$row['formatted_title'] = $titleBuilder
				->setNamespace( 6 )
				->appendTitleSegment( $targetFilename )
				->build();
			$row['data_base64'] = str_replace( 'data:image/png;base64,', '', $row['xml_png'] );
			unset( $row['xml_png'] );
			$this->buckets->addData( 'diagram-contents', $id, $row, false, false );
		}
	}

	/**
	 * Analyze attachments, table and files
	 *
	 * Generate pages and revisions for attachments
	 * @param SqlConnection $connection
	 */
	protected function analyzeAttachments( $connection ) {
		$res = $connection->query(
			"SELECT attachment_id, container_type, container_id, filename "
			. "FROM attachment_versions WHERE filename IN ("
				. "SELECT filename FROM attachment_versions "
				. "WHERE container_type IS NOT NULL "
				. "GROUP BY filename "
				. "HAVING COUNT(*) >= 2 "
			. ") AND container_type IS NOT NULL "
			. "ORDER BY filename;"
		);
		$samenameAttachments = [];
		foreach ( $res as $row ) {
			$filename = $row['filename'];
			unset( $row['filename'] );
			$id = $row['attachment_id'];
			unset( $row['attachment_id'] );
			$samenameAttachments[$filename][$id] = $row;
			$this->buckets->addData(
				'samename-attachments',
				$filename,
				$samenameAttachments[$filename],
				false,
				false
			);
		}

		$wikiPages = $this->buckets->getBucketData( 'wiki-pages' );
		$pageRevisions = $this->buckets->getBucketData( 'page-revisions' );
		$rows = [];
		$commonClause = "SELECT u.attachment_id, u.id AS revision_id, "
			. "u.version, u.author_id, u.created_on, u.updated_at, "
			. "u.description, u.filename, u.disk_directory, u.disk_filename, "
			. "u.content_type, u.filesize, u.digest, u.container_id "
			. "FROM attachment_versions u "
			. "INNER JOIN attachments a ON a.id = u.attachment_id ";
		$res = $connection->query(
			$commonClause
			. "INNER JOIN easy_knowledge_stories s ON u.container_id = s.id "
			. "WHERE u.container_type = 'EasyKnowledgeStory'; "
		);
		foreach ( $res as $row ) {
			if ( !isset( $wikiPages[$row['container_id']] ) ) {
				continue;
			}
			$pathPrefix = $row['disk_directory']
				? $row['disk_directory'] . DIRECTORY_SEPARATOR
				: '';
			$rows[$row['attachment_id']][$row['version']] = [
				'revision_id' => $row['revision_id'],
				'created_on' => $row['created_on'],
				'updated_at' => $row['updated_at'],
				'summary' => $row['description'],
				'user_id' => $row['author_id'],
				'filename' => $row['filename'],
				'source_path' => $pathPrefix . $row['disk_filename'],
				'target_filename' => isset( $samenameAttachments[$row['filename']] )
					? implode(
						'_',
						$samenameAttachments[$row['filename']][$row['attachment_id']]
					) . '_' . $row['filename']
					: $row['filename'],
				'quoted_page_id' => $row['container_id'],
			];
		}
		// Include attachments from static links
		$this->wantedAttachmentRevisions = array_values(
			array_unique( $this->wantedAttachmentRevisions )
		);
		print_r( $this->wantedAttachmentRevisions );
		if ( count( $this->wantedAttachmentRevisions ) > 0 ) {
			$wantedAttachments = $this->wantedAttachmentRevisions;
			$res = $connection->query(
				"SELECT u.attachment_id, u.id AS revision_id, "
				. "u.version, u.author_id, u.created_on, u.updated_at, "
				. "u.description, u.filename, u.disk_directory, u.disk_filename, "
				. "u.content_type, u.filesize, u.digest, u.container_id "
				. "FROM attachment_versions u "
				. "WHERE u.id IN "
				. "(" . implode( ", ", $wantedAttachments ) . "); "
			);
			foreach ( $res as $row ) {
				$pathPrefix = $row['disk_directory']
					? $row['disk_directory'] . DIRECTORY_SEPARATOR
					: '';
				$rows[$row['attachment_id']][$row['version']] = [
					'revision_id' => $row['revision_id'],
					'created_on' => $row['created_on'],
					'updated_at' => $row['updated_at'],
					'summary' => $row['description'],
					'user_id' => $row['author_id'],
					'filename' => $row['filename'],
					'source_path' => $pathPrefix . $row['disk_filename'],
					'target_filename' => isset( $samenameAttachments[$row['filename']] )
						? implode(
							'_',
							$samenameAttachments[$row['filename']][$row['attachment_id']]
						) . '_' . $row['filename']
						: $row['filename'],
					'quoted_content_id' => $row['container_id'],
				];
			}
		}
		$this->wantedAttachmentIds = array_values(
			array_unique( $this->wantedAttachmentIds )
		);
		print_r( $this->wantedAttachmentIds );
		if ( count( $this->wantedAttachmentIds ) > 0 ) {
			$wantedAttachments = $this->wantedAttachmentIds;
			$res = $connection->query(
				"SELECT u.attachment_id, u.id AS revision_id, "
				. "u.version, u.author_id, u.created_on, u.updated_at, "
				. "u.description, u.filename, u.disk_directory, u.disk_filename, "
				. "u.content_type, u.filesize, u.digest, u.container_id "
				. "FROM attachment_versions u "
				. "WHERE u.attachment_id IN "
				. "(" . implode( ", ", $wantedAttachments ) . "); "
			);
			foreach ( $res as $row ) {
				$pathPrefix = $row['disk_directory']
					? $row['disk_directory'] . DIRECTORY_SEPARATOR
					: '';
				$rows[$row['attachment_id']][$row['version']] = [
					'revision_id' => $row['revision_id'],
					'created_on' => $row['created_on'],
					'updated_at' => $row['updated_at'],
					'summary' => $row['description'],
					'user_id' => $row['author_id'],
					'filename' => $row['filename'],
					'source_path' => $pathPrefix . $row['disk_filename'],
					'target_filename' => isset( $samenameAttachments[$row['filename']] )
						? implode(
							'_',
							$samenameAttachments[$row['filename']][$row['attachment_id']]
						) . '_' . $row['filename']
						: $row['filename'],
					'quoted_content_id' => $row['container_id'],
				];
			}
		}
		// generate a dummy page with a dummy revision for each attachment
		// the only important thing is the title
		$wikiPages = [];
		foreach ( array_keys( $rows ) as $id ) {
			// store attachment versions elsewhere to generate batch script
			$this->buckets->addData( 'attachment-files', $id, $rows[$id], false, false );

			$maxVersion = max( array_keys( $rows[$id] ) );
			$file = $rows[$id][$maxVersion];
			$titleBuilder = new TitleBuilder( [] );
			$fTitle = $titleBuilder
				->setNamespace( 6 )
				->appendTitleSegment( $file['target_filename'] )
				->build();
			$dummyId = $id + 1000000000;
			$wikiPages[$dummyId] = [
				'attachment_revision_id' => $file['revision_id'],
				'title' => $file['filename'],
				'version' => 1,
				'formatted_title' => $fTitle,
				'parent_id' => null,
				'categories' => [],
			];
			$pageRevision = [
				1 => [
					'rev_id' => $file['revision_id'],
					'page_id' => $dummyId,
					'author_name' => $this->getUserName( $file['user_id'] ),
					'author_id' => $file['user_id'],
					'data' => '',
					'comments' => 'EKB-Stories-Migration: generated revision from attachment_versions table.',
					'updated_on' => $file['updated_at'],
					'parent_rev_id' => null,
				],
			];
			$this->buckets->addData( 'page-revisions', $dummyId, $pageRevision, false, false );
		}
		foreach ( array_keys( $wikiPages ) as $id ) {
			$this->buckets->addData( 'wiki-pages', $id, $wikiPages[$id], false, false );
		}
	}

	/**
	 * Output statistics
	 *
	 * @param SqlConnection $connection
	 */
	protected function doStatistics( $connection ) {
		print_r( "\nstatistics:\n" );

		$wikiPages = $this->buckets->getBucketData( 'wiki-pages' );
		print_r( " - " . count( $wikiPages ) . " pages loaded\n" );

		$pageRevisions = $this->buckets->getBucketData( 'page-revisions' );
		$revCount = 0;
		foreach ( array_keys( $pageRevisions ) as $page_id ) {
			$revCount += count( $pageRevisions[$page_id] );
			foreach ( array_keys( $pageRevisions[$page_id] ) as $ver ) {
				if ( !isset( $pageRevisions[$page_id][$ver]['author_name'] ) ) {
					print_r( "author_name not set for page_id: " . $page_id . " ver: " . $ver . "\n" );
					var_dump( $pageRevisions[$page_id][$ver] );
				}
			}
		}
		print_r( " - " . $revCount . " page revisions loaded\n" );

		$attachmentFiles = $this->buckets->getBucketData( 'attachment-files' );
		print_r( " - " . count( $attachmentFiles ) . " attachments loaded\n" );
		$fileCount = 0;
		foreach ( array_keys( $attachmentFiles ) as $id ) {
			$fileCount += count( $attachmentFiles[$id] );
		}
		print_r( " - " . $fileCount . " attachment versions loaded\n" );
	}

	/**
	 * @inheritDoc
	 */
	protected function analyzeRow( $row, $table ) {
		return true;
	}
}
