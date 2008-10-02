<?php
/**
 * @version $Id$
 * @copyright Center for History and New Media, 2007-2008
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 * @package Omeka
 **/

require_once 'Item.php';

/**
 * @see Omeka_Controller_Action
 **/
require_once 'Omeka/Controller/Action.php';

/**
 * @package Omeka
 * @subpackage Controllers
 * @author CHNM
 * @copyright Center for History and New Media, 2007-2008
 **/
class ItemsController extends Omeka_Controller_Action
{
    public function init() 
    {
        $this->_modelClass = 'Item';
    }
    
    /**
     * This only loads the script for the advanced search page.
     * 
     * @return void
     **/
    public function advancedSearchAction()
    {
        $this->view->isPartial = ($this->getRequest()->isXmlHttpRequest() or (boolean)$this->_getParam('is_partial'));
        
        $this->view->formAttributes = $this->_getParam('form_attributes');
        if (!$this->view->formAttributes) {
            $this->view->formAttributes = array();
        }        
    }
    
    protected function _getItemElementSets()
    {
        return $this->getTable('ElementSet')->findForItems();
    }
    
    /**
     * Adds an additional permissions check to the built-in edit action.
     * 
     * Also 
     *
     **/
    public function editAction()
    {
        // Get all the element sets that apply to the item.
        $this->view->elementSets = $this->_getItemElementSets();
        
        if ($user = $this->getCurrentUser()) {
            
            $item = $this->findById();
            
            // If the user cannot edit any given item. Check if they can edit 
            // this specific item
            if ($this->isAllowed('editAll') 
                || ($this->isAllowed('editSelf') && $item->wasAddedBy($user))) {
                return parent::editAction();    
            }
        }
        
        $this->forbiddenAction();
    }
    
    public function addAction()
    {
        // Get all the element sets that apply to the item.
        $this->view->elementSets = $this->_getItemElementSets();
        
        return parent::addAction();
    }
    
    /**
     * Wrapping this crap with permissions checks
     *
     **/
    public function deleteAction()
    {
        if ($user = $this->getCurrentUser()) {
            $item = $this->findById();
            
            // Permission check
            if ($this->isAllowed('deleteAll') 
                || ($this->isAllowed('deleteSelf') && $item->wasAddedBy($user))) {
                $item->delete();
                $this->redirect->goto('browse');
            }
        }
        
        $this->_forward('forbidden');
    }
    
    /**
     * Finds all tags associated with items (used for tag cloud)
     * 
     * @return void
     **/
    public function tagsAction()
    {
        $params = array_merge($this->_getAllParams(), array('type'=>'Item'));
        $tags = $this->getTable('Tag')->findBy($params);
        $this->view->assign(compact('tags'));
    }
    
    /**
     * Browse the items.  Encompasses search, pagination, and filtering of
     * request parameters.  Should perhaps be split into a separate
     * mechanism.
     * 
     * @return void
     **/
    public function browseAction()
    {   
        $results = $this->_helper->searchItems(array('foobar'=>true));
        
        /** 
         * Now process the pagination
         * 
         **/
        $paginationUrl = $this->getRequest()->getBaseUrl().'/items/browse/';

        //Serve up the pagination
        $pagination = array('menu'          => $menu, // This hasn't done anything since $menu was never instantiated in ItemsController::browseAction()
                            'page'          => $results['page'], 
                            'per_page'      => $results['per_page'], 
                            'total_results' => $results['total_results'], 
                            'link'          => $paginationUrl);
        
        Zend_Registry::set('pagination', $pagination);
        
        fire_plugin_hook('browse_items', $items);
        
        $this->view->assign(array('items'=>$results['items'], 'total_items'=>$results['total_items']));
    }
    
    public function elementFormAction()
    {
        // var_dump($_POST);exit;
        $elementId = (int)$_POST['element_id'];
        $itemId  = (int)$_POST['item_id'];
        
        // Re-index the element form posts so that they are displayed in the correct order
        // when one is removed.
        $_POST['Elements'][$elementId] = array_merge($_POST['Elements'][$elementId]);

        $element = $this->getTable('Element')->find($elementId);
        try {
            $item = $this->findById($itemId);
        } catch (Exception $e) {
            $item = new Item;
        }
        
        $this->view->assign(compact('element', 'item'));
    }
    
    ///// AJAX ACTIONS /////
    
    /**
     * Find or create an item for this mini-form
     *
     **/
    public function changeTypeAction()
    {
        if ($id = $_POST['item_id']) {
            $item = $this->findById($id);
        } else {
            $item = new Item;
        }
        
        $item->item_type_id = (int) $_POST['type_id'];
        $this->view->assign(compact('item'));
    }
    
    /**
     * Display the form for tags for a given item.
     * 
     * @return void
     **/
    public function tagFormAction()
    {
        $item = $this->findById();
        $this->view->assign(compact('item'));
    }
    
    /**
     * Modify the tags for an item (add or remove).  If this is an AJAX request, it will
     * render the 'tag-list' partial, otherwise it will redirect to the
     * 'show' action.
     * 
     * @return void
     **/
    public function modifyTagsAction()
    {
        $item = $this->findById();

        //Add the tags
         
        if (array_key_exists('modify_tags', $_POST) || !empty($_POST['tags'])) {
            if ($this->isAllowed('tag')) {
                $tagsAdded = $item->saveForm($_POST);
                $item = $this->findById();
            } else {
                $this->flash('User does not have permission to add tags.');
            }
        }
        
        if (!$this->getRequest()->isXmlHttpRequest()) {
            $itemId = $this->_getParam('id');
            return $this->redirect->gotoRoute(array('controller' => 'items', 
                                                    'action'     => 'show', 
                                                    'id'         => $itemId), 'id');
        }
        
        $this->view->assign(compact('item'));
        $this->render('tag-list');
    }
    
    ///// END AJAX ACTIONS /////
    
    /**
     * Change the 'public' or 'featured' status of items
     * 
     * @return void
     **/
    public function powerEditAction()
    {
        /*POST in this format:
                     items[1][public],
                     items[1][featured],
                    items[1][id],
                    items[2]...etc
        */
        if (empty($_POST)) {
            $this->redirect->goto('browse');
        }
        
        try {
            if (!$this->isAllowed('makePublic')) {
                throw new Exception( 'User is not allowed to modify visibility of items.' );
            }
            
            if (!$this->isAllowed('makeFeatured')) {
                throw new Exception( 'User is not allowed to modify featured status of items' );
            }

            if ($itemArray = $this->_getParam('items')) {
                    
                //Loop through the IDs given and toggle
                foreach ($itemArray as $k => $fields) {
                    
                    if(!array_key_exists('id', $fields) or
                    !array_key_exists('public', $fields) or
                    !array_key_exists('featured', $fields)) { 
                        throw new Exception( 'Power-edit request was mal-formed!' ); 
                    }
                    
                    $item = $this->findById($fields['id']);
                    
                    //Process the public field
                    
                    //Existing status must be compared against new status for the sake of plugin hooks
                    $old = $item->public;
                    $new = (int) $fields['public'];
                    
                    //If the item was made public, fire the plugin hook
                    if (!$old and $new) {
                        fire_plugin_hook('make_item_public', $item);
                    }
                    
                    //If public has been checked
                    $item->public = $new;
                    
                    $item->featured = (int) $fields['featured'];
                    
                    $item->save();
                }
            }
            
            $this->flashSuccess('Changes were successful');
            
        } catch (Exception $e) {
            $this->flash($e->getMessage());
        }
        
        $this->redirect->gotoUrl($_SERVER['HTTP_REFERER']);
    }
    
}