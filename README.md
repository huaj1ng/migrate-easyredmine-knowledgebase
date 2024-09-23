# Migrate EasyRedmine Knowledgebase XML export to MediaWiki import data

This is a command line tool to convert the contents of a EasyRedmine Knowledgebase space into a MediaWiki import data format.

## Prerequisites
1. PHP >= 8.2 with the `xml` extension must be installed
2. `pandoc` >= 3.1.6. The `pandoc` tool must be installed and available in the `PATH` (https://pandoc.org/installing.html).

## Installation
1. Download `migrate-easyredmine-knowledgebase.phar` from https://github.com/hallowelt/migrate-easyredmine-knowledgebase/releases/tag/latest
2. Make sure the file is executable. E.g. by running `chmod +x migrate-easyredmine-knowledgebase.phar`
3. Move `migrate-easyredmine-knowledgebase.phar` to `/usr/local/bin/migrate-easyredmine-knowledgebase` (or somewhere else in the `PATH`)

## Workflow

TBD