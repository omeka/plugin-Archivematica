<?php
class Job_PackageItemsSip extends Job_AbstractArchivematicaSip
{
    const ITEMS_PER_PAGE = 100;

    const DUBLIN_CORE_ELEMENTS = [
        'Title', 'Subject', 'Description', 'Creator', 'Source', 'Publisher', 'Date', 'Contributor',
        'Rights', 'Relation', 'Format', 'Language', 'Type', 'Identifier', 'Coverage',
    ];

    protected function _buildSip(ArchivematicaSip $sip)
    {
        if ($sip->source_type !== 'items') {
            throw new Exception(sprintf('Unsupported source type: %s', $sip->source_type));
        }

        $data = $sip->getData();
        $sipName = sprintf('items-%d-AM_SIP', $sip->id);
        $sipDirectoryPath = $this->_prepareSipDirectory($sipName);
        $this->_writeItemsMetadataCsv(
            $sipDirectoryPath,
            $data['query'] ?? [],
            !empty($data['include_items_without_files'])
        );
        $this->_zipSipDirectory($sipName);
        return $sipName;
    }

    /**
     * Copy each matched item's files into objects/ and write metadata.csv,
     * one row per object carrying its item's metadata. Runs the search
     * twice: once to collect every column for the header, once to write rows.
     */
    private function _writeItemsMetadataCsv($sipDirectoryPath, array $query, $includeItemsWithoutFiles)
    {
        $itemTable = $this->_db->getTable('Item');
        $pageCount = ceil($itemTable->count($query) / self::ITEMS_PER_PAGE);

        $columns = [];
        for ($page = 1; $page <= $pageCount; $page++) {
            foreach ($itemTable->findBy($query, self::ITEMS_PER_PAGE, $page) as $item) {
                $columns += $this->_itemMetadata($item);
            }
        }
        ksort($columns);
        $columns = array_keys($columns);

        $fp = fopen(sprintf('%s/metadata/metadata.csv', $sipDirectoryPath), 'w');
        fputcsv($fp, array_merge(['filename'], $columns), ',', '"', '');
        $rowCount = 0;
        for ($page = 1; $page <= $pageCount; $page++) {
            foreach ($itemTable->findBy($query, self::ITEMS_PER_PAGE, $page) as $item) {
                $metadata = $this->_itemMetadata($item);
                $values = [];
                foreach ($columns as $column) {
                    $values[] = $metadata[$column] ?? '';
                }

                $filenames = [];
                foreach ($item->getFiles() as $file) {
                    $filenames[] = $this->_copyOriginalFile($sipDirectoryPath, $item, $file);
                }
                if (!$filenames && $includeItemsWithoutFiles) {
                    $filenames[] = $this->_writeItemMetadataFile($sipDirectoryPath, $item, $metadata);
                }

                foreach ($filenames as $filename) {
                    fputcsv($fp, array_merge([$filename], $values), ',', '"', '');
                    $rowCount++;
                }
            }
        }
        fclose($fp);

        if (!$rowCount) {
            throw new Exception('No files or items to package.');
        }
    }

    // Map an item's element texts to metadata.csv columns, joining repeated values with "|"
    private function _itemMetadata(Item $item)
    {
        $metadata = [];
        foreach ($item->getAllElements() as $elementSetName => $elements) {
            foreach ($elements as $elementName => $element) {
                $texts = [];
                foreach ($item->getElementTextsByRecord($element) as $elementText) {
                    $texts[] = $elementText->text;
                }
                if (!$texts) {
                    continue;
                }
                $value = $this->_truncateFieldValue(implode('|', $texts));
                $metadata[$this->_columnName($elementSetName, $elementName)] = $this->_stripInvalidXmlChars($value);
            }
        }
        return $metadata;
    }

    // Core Dublin Core elements get Archivematica's dc. prefix; others get a slugged set.element name
    private function _columnName($elementSetName, $elementName)
    {
        if ($elementSetName === 'Dublin Core' && in_array($elementName, self::DUBLIN_CORE_ELEMENTS)) {
            return sprintf('dc.%s', strtolower($elementName));
        }
        return sprintf('%s.%s', $this->_slug($elementSetName), $this->_slug($elementName));
    }

    private function _slug($name)
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
    }

    // Copy a file's original into objects/, prefixed with item and file IDs so names can't collide
    private function _copyOriginalFile($sipDirectoryPath, Item $item, File $file)
    {
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->original_filename);
        $filename = sprintf('objects/%d_%d_%s', $item->id, $file->id, $safeName);
        $toPath = sprintf('%s/%s', $sipDirectoryPath, $filename);

        // Same as StaticSiteExport: copy() on the filesystem adapter, otherwise stream over HTTP
        $storage = Zend_Registry::get('storage');
        if ($storage->getAdapter() instanceof Omeka_Storage_Adapter_Filesystem) {
            $fromPath = sprintf('%s/files/%s', BASE_DIR, $file->getStoragePath('original'));
            if (!copy($fromPath, $toPath)) {
                throw new Exception(sprintf('Could not copy file into SIP: %s', $fromPath));
            }
        } else {
            $client = new Omeka_Http_Client;
            $client->setUri($file->getWebPath('original'))->setStream($toPath)->send();
        }
        return $filename;
    }

    // Archivematica needs a real object for every metadata.csv row, so give a fileless item a JSON snapshot
    private function _writeItemMetadataFile($sipDirectoryPath, Item $item, array $metadata)
    {
        $filename = sprintf('objects/%d_metadata.json', $item->id);
        file_put_contents(sprintf('%s/%s', $sipDirectoryPath, $filename), json_encode($metadata, JSON_PRETTY_PRINT));
        return $filename;
    }
}
