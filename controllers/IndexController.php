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

    public function packageAction()
    {
        $csrf = new Omeka_Form_SessionCsrf;
        if (!$this->getRequest()->isPost() || !$csrf->isValid($_POST)) {
            $this->_helper->flashMessenger(__('There were errors found in your form. Please edit and resubmit.'), 'error');
            $this->_helper->redirector('create-static-site');
            return;
        }

        $staticSiteId = (int) $this->getRequest()->getParam('id');
        $existing = $this->_helper->db->getTable('ArchivematicaSip')->findBySource('static_site', $staticSiteId);
        if ($existing) {
            $this->_helper->flashMessenger(__('This export has already been packaged or is in progress.'), 'error');
            $this->_helper->redirector('create-static-site');
            return;
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
}
