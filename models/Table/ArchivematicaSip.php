<?php
class Table_ArchivematicaSip extends Omeka_Db_Table
{
    public function findBySource($sourceType, $sourceId)
    {
        return $this->findBySql('source_type = ? AND source_id = ?', [$sourceType, $sourceId], true);
    }

    public function findAllWithSourceLabels()
    {
        $db = $this->getDb();
        $sql = "
        SELECT
            sip.id AS id,
            sip.source_type AS source_type,
            sip.source_id AS source_id,
            sip.status AS status,
            sip.sip_name AS sip_name,
            sip.error_message AS error_message,
            sip.added AS added,
            static_site.name AS static_site_name
        FROM `$db->ArchivematicaSip` AS sip
        LEFT JOIN `$db->StaticSite` AS static_site
            ON sip.source_type = 'static_site' AND sip.source_id = static_site.id
        ORDER BY sip.added DESC";
        return $db->fetchAll($sql);
    }

    public function findCompletedStaticSiteExports()
    {
        $db = $this->getDb();
        $sql = "
        SELECT
            static_site.id AS static_site_id,
            static_site.name AS static_site_name,
            static_site.added AS static_site_added,
            sip.id AS sip_id,
            sip.status AS sip_status,
            sip.sip_name AS sip_name,
            sip.error_message AS sip_error_message
        FROM `$db->StaticSite` AS static_site
        LEFT JOIN `$db->ArchivematicaSip` AS sip
            ON sip.source_type = 'static_site' AND sip.source_id = static_site.id
        WHERE static_site.status = ?
        ORDER BY static_site.added DESC";
        return $db->fetchAll($sql, [Process::STATUS_COMPLETED]);
    }
}
