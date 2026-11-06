<?php
class Archivematica_IndexController extends Omeka_Controller_AbstractActionController
{
    public function init()
    {
        $this->_helper->db->setDefaultModelName('ArchivematicaSip');
    }

    public function indexAction()
    {
        $this->view->sips = $this->_helper->db->getTable('ArchivematicaSip')->findAllWithSourceLabels();
    }

    public function createAction()
    {
    }

    public function createStaticSiteAction()
    {
        $csrf = new Omeka_Form_SessionCsrf;
        $this->view->csrf = $csrf;
        $this->view->staticSiteExports = $this->_helper->db->getTable('ArchivematicaSip')
            ->findCompletedStaticSiteExports();
    }

    public function createItemsAction()
    {
        $this->view->csrf = new Omeka_Form_SessionCsrf;
        $query = $this->getRequest()->getQuery();
        if (!isset($query['submit_search'])) {
            return;
        }
        unset($query['submit_search'], $query['page']);

        $itemTable = $this->_helper->db->getTable('Item');
        $itemCount = $itemTable->count($query);
        $perPage = (int) get_option('per_page_admin');
        $page = $this->getParam('page', 1);
        // Used by pagination_links()
        Zend_Registry::set('pagination', [
            'page' => $page,
            'per_page' => $perPage,
            'total_results' => $itemCount,
        ]);

        $this->view->query = $query;
        $this->view->itemCount = $itemCount;
        $this->view->items = $itemTable->findBy($query, $perPage, $page);
    }

    public function packageStaticSiteAction()
    {
        $csrf = new Omeka_Form_SessionCsrf;
        if (!$this->getRequest()->isPost() || !$csrf->isValid($_POST)) {
            $this->_helper->flashMessenger(__('There were errors found in your form. Please edit and resubmit.'), 'error');
            $this->_helper->redirector('create-static-site');
            return;
        }

        $staticSiteId = (int) $this->getRequest()->getParam('id');
        // Recreating a SIP replaces the previous record and its zip
        $existing = $this->_helper->db->getTable('ArchivematicaSip')->findBySource('static_site', $staticSiteId);
        if ($existing) {
            if (in_array($existing->getStatus(), [Process::STATUS_STARTING, Process::STATUS_IN_PROGRESS])) {
                $this->_helper->flashMessenger(__('A SIP for this export is already being created.'), 'error');
                $this->_helper->redirector('create-static-site');
                return;
            }
            $existing->delete();
        }

        $sip = new ArchivematicaSip;
        $sip->source_type = 'static_site';
        $sip->source_id = $staticSiteId;
        $sip->setStatus(Process::STATUS_STARTING);
        $sip->save();

        $dispatcher = Zend_Registry::get('job_dispatcher');
        $dispatcher->sendLongRunning('Job_PackageStaticSiteSip', ['archivematica_sip_id' => $sip->id]);

        $this->_helper->flashMessenger(__('Packaging the static site export as an Archivematica SIP.'), 'success');
        $this->_helper->redirector('index');
    }

    public function packageItemsAction()
    {
        $csrf = new Omeka_Form_SessionCsrf;
        if (!$this->getRequest()->isPost() || !$csrf->isValid($_POST)) {
            $this->_helper->flashMessenger(__('There were errors found in your form. Please edit and resubmit.'), 'error');
            $this->_helper->redirector('create-items');
            return;
        }

        parse_str($this->getRequest()->getPost('query', ''), $query);
        $includeItemsWithoutFiles = (bool) $this->getRequest()->getPost('include_items_without_files');

        $db = get_db();
        $itemTable = $db->getTable('Item');
        $select = $itemTable->getSelectForCount($query);
        if (!$includeItemsWithoutFiles) {
            $alias = $itemTable->getTableAlias();
            $select->where("EXISTS (SELECT 1 FROM `$db->File` AS files WHERE files.item_id = $alias.id)");
        }

        $sip = new ArchivematicaSip;
        $sip->source_type = 'items';
        $sip->source_id = 0;
        $sip->setData([
            'query' => $query,
            'item_count' => (int) $db->fetchOne($select),
            'include_items_without_files' => $includeItemsWithoutFiles,
        ]);
        $sip->setStatus(Process::STATUS_STARTING);
        $sip->save();

        $dispatcher = Zend_Registry::get('job_dispatcher');
        $dispatcher->sendLongRunning('Job_PackageItemsSip', ['archivematica_sip_id' => $sip->id]);

        $this->_helper->flashMessenger(__('Packaging the items as an Archivematica SIP.'), 'success');
        $this->_helper->redirector('index');
    }
}
