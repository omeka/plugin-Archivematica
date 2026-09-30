<?php
class Job_PackageStaticSiteSip extends Omeka_Job_AbstractJob
{
    const MAX_FIELD_LENGTH = 100000;

    public function perform()
    {
        $sip = $this->_db->getTable('ArchivematicaSip')->find($this->_options['archivematica_sip_id']);
        if (!$sip) {
            _log(sprintf(
                'Archivematica: no ArchivematicaSip record found for id %s',
                $this->_options['archivematica_sip_id']
            ), Zend_Log::ERR);
            return;
        }

        try {
            if ($sip->source_type !== 'static_site') {
                throw new Exception(sprintf('Unsupported source type: %s', $sip->source_type));
            }

            $sip->setStatus(Process::STATUS_IN_PROGRESS);
            $sip->save();

            $staticSite = $this->_db->getTable('StaticSite')->find($sip->source_id);
            if (!$staticSite) {
                throw new Exception('Static site export not found.');
            }

            $sitesDirectoryPath = get_option('static_site_export_sites_directory_path');
            $sipsDirectoryPath = get_option('archivematica_sips_directory_path');
            if (!$sipsDirectoryPath) {
                throw new Exception('Archivematica SIPs directory path is not configured.');
            }

            $sourceZipPath = sprintf('%s/%s.zip', $sitesDirectoryPath, $staticSite->getName());
            if (!is_file($sourceZipPath)) {
                throw new Exception(sprintf('Static site export zip not found: %s', $sourceZipPath));
            }

            $sipName = sprintf('%s-AM_SIP', $staticSite->getName());
            $sipDirectoryPath = sprintf('%s/%s', $sipsDirectoryPath, $sipName);
            $sipZipPath = sprintf('%s/%s.zip', $sipsDirectoryPath, $sipName);

            // Remove any previous SIP for this export, including leftovers from a failed run
            if (is_file($sipZipPath)) {
                unlink($sipZipPath);
            }
            if (is_dir($sipDirectoryPath)) {
                $this->_shellExec(sprintf('rm -r %s', escapeshellarg($sipDirectoryPath)));
            }

            mkdir(sprintf('%s/objects', $sipDirectoryPath), 0755, true);
            mkdir(sprintf('%s/metadata', $sipDirectoryPath), 0755, true);
            $objectFilename = sprintf('objects/%s.zip', $staticSite->getName());
            if (!copy($sourceZipPath, sprintf('%s/%s', $sipDirectoryPath, $objectFilename))) {
                throw new Exception(sprintf('Could not copy static site export zip into SIP: %s', $sourceZipPath));
            }

            $this->_writeStaticSiteMetadataCsv($sipDirectoryPath, $objectFilename, $staticSite);

            // Archivematica expects objects/ and metadata/ at the zip root, not under a wrapping directory
            $this->_shellExec(sprintf(
                'cd %s && zip --recurse-paths %s objects metadata',
                escapeshellarg($sipDirectoryPath),
                escapeshellarg($sipZipPath)
            ));
            $this->_shellExec(sprintf('rm -r %s', escapeshellarg($sipDirectoryPath)));

            $sip->sip_name = $sipName;
            $sip->setStatus(Process::STATUS_COMPLETED);
            $sip->save();
        } catch (\Throwable $e) {
            $sip->error_message = $e->getMessage();
            $sip->setStatus(Process::STATUS_ERROR);
            $sip->save();
            _log(sprintf("%s\n%s", $e->getMessage(), $e->getTraceAsString()), Zend_Log::ERR);
        }
    }

    private function _shellExec($command)
    {
        $output = shell_exec($command);
        if (false === $output) {
            throw new Exception(sprintf('Archivematica: invalid command: %s', $command));
        }
    }

    // Write a single metadata.csv row describing the whole static site export
    private function _writeStaticSiteMetadataCsv($sipDirectoryPath, $objectFilename, $staticSite)
    {
        $fp = fopen(sprintf('%s/metadata/metadata.csv', $sipDirectoryPath), 'w');
        fputcsv($fp, ['filename', 'dc.title', 'dc.description', 'dc.identifier'], ',', '"', '');
        fputcsv($fp, [
            $objectFilename,
            $this->_stripInvalidXmlChars((string) get_option('site_title')),
            $this->_stripInvalidXmlChars((string) get_option('description')),
            $this->_stripInvalidXmlChars((string) $staticSite->getDataValue('base_url')),
        ], ',', '"', '');
        fclose($fp);
    }

    /**
     * Write a SIP metadata.csv, one row per exported digital object.
     *
     * Walks every exported item; an item with attached files gets one row
     * per file (columns from that file's own element_texts.json). When the 
     * "Include items without files" plugin setting is on, an item
     * with no files gets a row pointing at a generated JSON metadata
     * snapshot instead — Archivematica requires every metadata.csv row to
     * reference a real file in the transfer, and Static Site Export still
     * writes item bundles (with their own element_texts.json) for items
     * that have no files.
     */
    private function _writeMetadataCsv($sipDirectoryPath)
    {
        $rows = [];
        $headerColumns = [];
        $includeItemsWithoutFiles = (bool) get_option('archivematica_include_items_without_files');

        foreach (glob(sprintf('%s/content/items/*', $sipDirectoryPath), GLOB_ONLYDIR) as $itemDirectoryPath) {
            $indexPath = sprintf('%s/index.md', $itemDirectoryPath);
            if (!is_file($indexPath)) {
                continue;
            }
            $frontMatter = json_decode(file_get_contents($indexPath), true);
            $fileParams = $frontMatter['params']['files'] ?? [];

            // Write a row for item with file/s
            if ($fileParams) {
                foreach ($fileParams as $fileParam) {
                    $fileDirectoryPath = sprintf('%s/content/files/%s', $sipDirectoryPath, $fileParam['id']);
                    $objectPaths = glob(sprintf('%s/file.*', $fileDirectoryPath));
                    if (!$objectPaths) {
                        continue;
                    }
                    $filename = ltrim(str_replace($sipDirectoryPath, '', $objectPaths[0]), '/');
                    $row = $this->_rowFromElementTexts(
                        sprintf('%s/element_texts.json', $fileDirectoryPath),
                        $filename,
                        $headerColumns
                    );
                    if ($row) {
                        $rows[] = $row;
                    }
                }
            } elseif ($includeItemsWithoutFiles) {
                // Write a row for item without file/s
                $elementTextsPath = sprintf('%s/element_texts.json', $itemDirectoryPath);
                if (!is_file($elementTextsPath)) {
                    continue;
                }
                $itemId = basename($itemDirectoryPath);
                $snapshotFilename = sprintf('%s_metadata.json', $itemId);
                copy($elementTextsPath, sprintf('%s/%s', $itemDirectoryPath, $snapshotFilename));

                $filename = sprintf('content/items/%s/%s', $itemId, $snapshotFilename);
                $row = $this->_rowFromElementTexts($elementTextsPath, $filename, $headerColumns);
                if ($row) {
                    $rows[] = $row;
                }
            }
        }

        if (!$rows) {
            return;
        }

        ksort($headerColumns);
        $columns = array_keys($headerColumns);

        $fp = fopen(sprintf('%s/metadata.csv', $sipDirectoryPath), 'w');
        fputcsv($fp, array_merge(['filename'], $columns), ',', '"', '');
        foreach ($rows as $row) {
            $line = [$row['filename']];
            foreach ($columns as $column) {
                $line[] = $row[$column] ?? '';
            }
            fputcsv($fp, $line, ',', '"', '');
        }
        fclose($fp);
    }

    /**
     * Build one metadata.csv row from an element_texts.json file, tracking
     * every column name seen onto $headerColumns by reference.
     */
    private function _rowFromElementTexts($elementTextsPath, $filename, array &$headerColumns)
    {
        $elementTexts = json_decode(file_get_contents($elementTextsPath), true);
        if (!is_array($elementTexts)) {
            return null;
        }

        $row = ['filename' => $filename];
        foreach ($elementTexts as $elementSet) {
            foreach ($elementSet['elements'] as $element) {
                if (empty($element['texts'])) {
                    continue;
                }
                $column = sprintf('%s.%s', $elementSet['name'], $element['name']);
                $value = $this->_truncateFieldValue(implode('|', $element['texts']));
                $value = $this->_stripInvalidXmlChars($value);
                $row[$column] = $value;
                $headerColumns[$column] = $column;
            }
        }
        return $row;
    }

    /**
     * Archivematica parses metadata.csv with Python's csv module, which has a
     * hard-coded 131072-byte field size limit. Exceeding it aborts METS
     * generation for the whole SIP, so values are truncated well below that.
     */
    private function _truncateFieldValue($value)
    {
        if (strlen($value) > self::MAX_FIELD_LENGTH) {
            return substr($value, 0, self::MAX_FIELD_LENGTH) . '... [truncated]';
        }
        return $value;
    }

    /**
     * Remove characters that lxml (used by Archivematica's METS generation)
     * refuses to serialize as XML text, such as the form feed page breaks
     * OCR/PDF text extraction leaves behind. Left in place, one aborts METS
     * generation for the whole SIP with "All strings must be XML compatible".
     */
    private function _stripInvalidXmlChars($value)
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
    }
}
