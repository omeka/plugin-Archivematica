<?php
class Job_ImportDip extends Omeka_Job_AbstractJob
{
    const METS_NS = 'http://www.loc.gov/METS/';
    const XLINK_NS = 'http://www.w3.org/1999/xlink';

    // {UUID}-{id}_metadata.json: the placeholder Omeka S and Classic SIPs write for an item with no media
    const PLACEHOLDER_PATTERN = '/^[0-9a-f-]{36}-\d+_metadata\.json$/i';

    // METS Dublin Core terms mapped to Classic's Dublin Core elements, which have no Provenance
    const DC_ELEMENTS = [
        'title' => 'Title',
        'description' => 'Description',
        'creator' => 'Creator',
        'subject' => 'Subject',
        'date' => 'Date',
        'format' => 'Format',
        'identifier' => 'Identifier',
        'rights' => 'Rights',
        'publisher' => 'Publisher',
        'contributor' => 'Contributor',
        'type' => 'Type',
        'source' => 'Source',
        'language' => 'Language',
        'relation' => 'Relation',
        'coverage' => 'Coverage',
        'provenance' => 'Source',
    ];

    public function perform()
    {
        $dipPath = $this->_options['dip_path'];
        $import = $this->_db->getTable('ArchivematicaImport')->find($this->_options['archivematica_import_id']);
        if (!$import) {
            _log(sprintf(
                'Archivematica: no ArchivematicaImport record found for id %s',
                $this->_options['archivematica_import_id']
            ), Zend_Log::ERR);
            return;
        }

        $tempDir = null;
        try {
            $import->setStatus(Process::STATUS_IN_PROGRESS);
            $import->save();

            $tempDir = $this->_extractArchive($dipPath);
            $this->_validateExtractedPaths($tempDir);
            $metsPath = $this->_findMets($tempDir);
            if (!$metsPath) {
                throw new Exception('No METS file found in DIP archive.');
            }

            $dom = new DOMDocument;
            $dom->load($metsPath);
            $this->_validateMets($dom);

            $globalRights = $this->_parseRights($dom);
            preg_match('/METS\.([0-9a-f-]{36})\.xml$/i', basename($metsPath), $matches);
            $packageUuid = $matches[1] ?? null;

            $filePathMap = $this->_buildFilePathMap($dom, $metsPath);
            foreach ($this->_parseEntities($dom) as $entity) {
                $metadata = $this->_parseDc($dom, $entity['dmdid']);
                foreach ($globalRights as $rightsValue) {
                    $metadata['Rights'][] = $rightsValue;
                }
                $filePaths = $entity['file_ids']
                    ? array_values(array_filter(array_map(fn ($id) => $filePathMap[$id] ?? null, $entity['file_ids'])))
                    : array_values($filePathMap);
                $filePaths = array_values(array_filter(
                    $filePaths,
                    fn ($path) => !preg_match(self::PLACEHOLDER_PATTERN, basename($path))
                ));

                $fileUuid = $entity['file_ids'] ? preg_replace('/^file-/', '', $entity['file_ids'][0]) : null;
                $entityUri = $packageUuid
                    ? ($fileUuid ? $packageUuid . '/' . $fileUuid : $packageUuid)
                    : null;

                $archivematicaItem = $entityUri
                    ? $this->_db->getTable('ArchivematicaItem')->findBySql('uri = ?', [$entityUri], true)
                    : null;
                $item = $archivematicaItem ? $this->_db->getTable('Item')->find($archivematicaItem->item_id) : null;
                if ($item) {
                    $this->_updateItem($item, $archivematicaItem, $metadata, $import);
                    $import->updated_count++;
                } else {
                    if ($archivematicaItem) {
                        $archivematicaItem->delete();
                    }
                    $this->_createItem($metadata, $filePaths, $entityUri, $import);
                    $import->added_count++;
                }
            }
            $import->setStatus(Process::STATUS_COMPLETED);
        } catch (\Throwable $e) {
            $import->error_message = $this->_toUtf8($e->getMessage());
            $import->setStatus(Process::STATUS_ERROR);
            _log(sprintf("%s\n%s", $e->getMessage(), $e->getTraceAsString()), Zend_Log::ERR);
        } finally {
            if ($tempDir && is_dir($tempDir)) {
                $this->_deleteDir($tempDir);
            }
            if (is_file($dipPath)) {
                unlink($dipPath);
            }
            // A failed final save must not leave the import stuck "in progress"
            try {
                $import->save();
            } catch (\Throwable $e) {
                _log(sprintf("%s\n%s", $e->getMessage(), $e->getTraceAsString()), Zend_Log::ERR);
                $this->_db->update(
                    $this->_db->ArchivematicaImport,
                    ['status' => Process::STATUS_ERROR, 'error_message' => 'Could not save import results: ' . $this->_toUtf8($e->getMessage())],
                    ['id = ?' => $import->id]
                );
            }
        }
    }

    private function _createItem(array $metadata, array $filePaths, $entityUri, ArchivematicaImport $import)
    {
        $item = insert_item(
            ['public' => $import->public, 'collection_id' => $import->collection_id],
            $this->_elementTexts($metadata)
        );
        // One file at a time so a rejected file is recorded by name and the rest still attach
        foreach ($filePaths as $filePath) {
            try {
                insert_files_for_item($item, 'Filesystem', [$filePath]);
            } catch (Omeka_File_Ingest_InvalidException $e) {
                $import->addSkippedFile(basename($filePath));
            }
        }

        $archivematicaItem = new ArchivematicaItem;
        $archivematicaItem->item_id = $item->id;
        $archivematicaItem->uri = $entityUri;
        $this->_trackItem($archivematicaItem, $import);
    }

    // Replace the item's values for each Dublin Core element the DIP supplies, leaving the rest
    private function _updateItem(Item $item, ArchivematicaItem $archivematicaItem, array $metadata, ArchivematicaImport $import)
    {
        $elementTable = $this->_db->getTable('Element');
        $elementIds = [];
        foreach (array_keys($metadata) as $elementName) {
            $elementIds[] = $elementTable->findByElementSetNameAndElementName('Dublin Core', $elementName)->id;
        }
        if ($elementIds) {
            $item->deleteElementTextsByElementId($elementIds);
        }
        update_item($item, [], $this->_elementTexts($metadata));
        $this->_trackItem($archivematicaItem, $import);
    }

    // Point the item's tracking row at this import, for its item count and undo
    private function _trackItem(ArchivematicaItem $archivematicaItem, ArchivematicaImport $import)
    {
        $archivematicaItem->import_id = $import->id;
        $archivematicaItem->last_modified = date('Y-m-d H:i:s');
        $archivematicaItem->save();
    }

    private function _elementTexts(array $metadata)
    {
        $elementTexts = [];
        foreach ($metadata as $elementName => $values) {
            foreach ($values as $value) {
                $elementTexts['Dublin Core'][$elementName][] = ['text' => $value, 'html' => false];
            }
        }
        return $elementTexts;
    }

    private function _extractArchive($archivePath)
    {
        $tempDir = sprintf('%s/archivematica_dip_%s', sys_get_temp_dir(), uniqid());
        mkdir($tempDir, 0755, true);
        try {
            $phar = new PharData($archivePath);
            $phar->extractTo($tempDir);
        } catch (Exception $e) {
            // Phar's own message can quote binary garbage from a corrupt file, so log it rather than show it
            _log('Archivematica: ' . $e->getMessage(), Zend_Log::ERR);
            $this->_deleteDir($tempDir);
            throw new Exception('Could not open DIP archive. The file may be corrupt or not a .tar archive.');
        }
        return $tempDir;
    }

    // Replace invalid bytes and 4-byte characters, which the utf8 (3-byte) columns reject
    private function _toUtf8($message)
    {
        $message = mb_convert_encoding($message, 'UTF-8', 'UTF-8');
        return preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\u{FFFD}", $message);
    }

    // Guard against tar slip: every extracted file must be inside $tempDir
    private function _validateExtractedPaths($tempDir)
    {
        $realTempDir = realpath($tempDir);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $realPath = realpath($file->getPathname());
            if ($realPath !== false
                && $realPath !== $realTempDir
                && strpos($realPath, $realTempDir . DIRECTORY_SEPARATOR) !== 0
            ) {
                throw new Exception('Archive contains a path traversal entry: ' . $file->getPathname());
            }
        }
    }

    private function _findMets($tempDir)
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tempDir));
        foreach ($iterator as $file) {
            if ($file->isFile() && preg_match('/^METS\..+\.xml$/i', $file->getFilename())) {
                return $file->getPathname();
            }
        }
        return null;
    }

    // Check the METS looks like an Archivematica DIP and has no path traversal in hrefs
    private function _validateMets(DOMDocument $dom)
    {
        $xpath = $this->_xpath($dom);
        if (!$xpath->query('/mets:mets')->item(0)) {
            throw new Exception('Not a valid METS document. This may not be an Archivematica DIP.');
        }
        if (!$xpath->query('//mets:fileGrp[@USE="original"]')->item(0)) {
            throw new Exception('METS has no original file group. This may not be an Archivematica DIP.');
        }
        foreach ($xpath->query('//mets:FLocat') as $flocat) {
            $href = $flocat->getAttributeNS(self::XLINK_NS, 'href');
            // Match ".." as a path segment only; filenames like "Memorial..jpg" are fine
            $segments = explode('/', str_replace('\\', '/', $href));
            if ($href !== '' && (in_array('..', $segments, true) || strpos($href, "\0") !== false)) {
                throw new Exception('Suspicious file path in METS: ' . $href);
            }
        }
    }

    // One entity per logical structMap div with a DMDID, then physical Item divs,
    // then a single entity covering every file
    private function _parseEntities(DOMDocument $dom)
    {
        $xpath = $this->_xpath($dom);
        $entities = [];

        foreach ($xpath->query('//mets:structMap[@TYPE="logical"]//mets:div[@DMDID]') as $div) {
            $fileIds = [];
            foreach ($xpath->query('.//mets:fptr', $div) as $fptr) {
                $fileIds[] = $fptr->getAttribute('FILEID');
            }
            $entities[] = ['dmdid' => $div->getAttribute('DMDID'), 'file_ids' => $fileIds];
        }

        if (!$entities) {
            foreach ($xpath->query('//mets:structMap[@TYPE="physical"]//mets:div[@TYPE="Item"][@DMDID]') as $div) {
                $fileIds = [];
                foreach ($xpath->query('mets:fptr', $div) as $fptr) {
                    $fileIds[] = $fptr->getAttribute('FILEID');
                }
                $entities[] = ['dmdid' => $div->getAttribute('DMDID'), 'file_ids' => $fileIds];
            }
        }

        if (!$entities) {
            $dmdSec = $xpath->query('//mets:dmdSec[1]')->item(0);
            $entities[] = ['dmdid' => $dmdSec ? $dmdSec->getAttribute('ID') : null, 'file_ids' => []];
        }

        return $entities;
    }

    // Return Dublin Core values keyed by Classic element name
    private function _parseDc(DOMDocument $dom, $dmdSecId)
    {
        $xpath = $this->_xpath($dom);
        $metadata = [];

        // DMDID is a space-separated list; use the dmdSec with MDTYPE="DC"
        if ($dmdSecId) {
            $dcSec = null;
            foreach (preg_split('/\s+/', trim($dmdSecId)) as $id) {
                $wrap = $xpath->query('//mets:dmdSec[@ID="' . $id . '"]/mets:mdWrap[@MDTYPE="DC"]')->item(0);
                if ($wrap) {
                    $dcSec = $wrap->parentNode;
                    break;
                }
            }
            if (!$dcSec) {
                return $metadata;
            }
            $nodes = $xpath->query('.//*', $dcSec);
        } else {
            $nodes = $xpath->query('//mets:dmdSec[mets:mdWrap[@MDTYPE="DC"]]//*');
        }

        foreach ($nodes as $node) {
            if (!in_array($node->namespaceURI, ['http://purl.org/dc/elements/1.1/', 'http://purl.org/dc/terms/'])) {
                continue;
            }
            $elementName = self::DC_ELEMENTS[$node->localName] ?? null;
            $value = trim($node->textContent);
            if ($elementName && $value !== '') {
                $metadata[$elementName][] = $value;
            }
        }
        return $metadata;
    }

    // Map each original file's METS ID to its extracted path. DIP objects are
    // renamed {UUID}-{original name}, so match by UUID before falling back to the href.
    private function _buildFilePathMap(DOMDocument $dom, $metsPath)
    {
        $metsDir = dirname($metsPath);
        $xpath = $this->_xpath($dom);

        $uuidToPath = [];
        $objectsDir = $metsDir . '/objects';
        if (is_dir($objectsDir)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($objectsDir));
            foreach ($iterator as $file) {
                if ($file->isFile() && preg_match('/^([0-9a-f-]{36})-/i', $file->getFilename(), $matches)) {
                    $uuidToPath[$matches[1]] = $file->getPathname();
                }
            }
        }

        $map = [];
        foreach ($xpath->query('//mets:fileGrp[@USE="original"]//mets:file') as $fileNode) {
            $fileId = $fileNode->getAttribute('ID');
            $uuid = preg_replace('/^file-/', '', $fileId);
            if (isset($uuidToPath[$uuid])) {
                $map[$fileId] = $uuidToPath[$uuid];
                continue;
            }
            $flocat = $xpath->query('mets:FLocat', $fileNode)->item(0);
            $href = $flocat ? $flocat->getAttributeNS(self::XLINK_NS, 'href') : '';
            $fullPath = $href ? realpath($metsDir . '/' . $href) : false;
            if ($fullPath && is_file($fullPath)) {
                $map[$fileId] = $fullPath;
            }
        }
        return $map;
    }

    // PREMIS rights statements as "Basis: detail; detail" strings. Archivematica
    // uses PREMIS v3 in recent versions and v2 in older ones.
    private function _parseRights(DOMDocument $dom)
    {
        $xpath = $this->_xpath($dom);
        $rights = [];

        foreach (['http://www.loc.gov/premis/v3', 'http://www.loc.gov/premis/v2'] as $premisNs) {
            $xpath->registerNamespace('premis', $premisNs);
            foreach ($xpath->query('//mets:amdSec//premis:rightsStatement') as $statement) {
                $basisNode = $xpath->query('premis:rightsBasis', $statement)->item(0);
                if (!$basisNode) {
                    continue;
                }
                $basis = trim($basisNode->textContent);

                switch (strtolower($basis)) {
                    case 'copyright':
                        $paths = [
                            'premis:copyrightInformation/premis:copyrightStatus',
                            'premis:copyrightInformation/premis:copyrightJurisdiction',
                            'premis:copyrightInformation/premis:copyrightNote',
                        ];
                        break;
                    case 'license':
                        $paths = [
                            'premis:licenseInformation/premis:licenseTerms',
                            'premis:licenseInformation/premis:licenseNote',
                        ];
                        break;
                    case 'statute':
                        $paths = [
                            'premis:statuteInformation/premis:statuteCitation',
                            'premis:statuteInformation/premis:statuteJurisdiction',
                            'premis:statuteInformation/premis:statuteNote',
                        ];
                        break;
                    default:
                        $paths = ['premis:otherRightsInformation/premis:otherRightsNote'];
                        break;
                }
                $paths[] = 'premis:rightsGranted/premis:rightsGrantedNote';

                $detail = [];
                foreach ($paths as $path) {
                    $node = $xpath->query($path, $statement)->item(0);
                    $value = $node ? trim($node->textContent) : '';
                    if ($value === '') {
                        continue;
                    }
                    $detail[] = strpos($path, 'Jurisdiction') !== false ? '(' . $value . ')' : $value;
                }
                $rights[] = $detail ? $basis . ': ' . implode('; ', $detail) : $basis;
            }

            if ($rights) {
                break;
            }
        }

        return $rights;
    }

    private function _xpath(DOMDocument $dom)
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('mets', self::METS_NS);
        return $xpath;
    }

    private function _deleteDir($dir)
    {
        foreach (array_diff(scandir($dir), ['.', '..']) as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->_deleteDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
