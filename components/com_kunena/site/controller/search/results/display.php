<?php
/**
 * Kunena Component
 * @package     Kunena.Site
 * @subpackage  Controller.Search
 *
 * @copyright   (C) 2008 - 2013 Kunena Team. All rights reserved.
 * @license     http://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link        http://www.kunena.org
 **/
defined('_JEXEC') or die;

/**
 * Class ComponentKunenaControllerSearchResultsDisplay
 *
 * @since  3.1
 */
class ComponentKunenaControllerSearchResultsDisplay extends KunenaControllerDisplay
{
	protected $name = 'Search/Results';

	/**
	 * @var KunenaModelSearch
	 */
	public $model;

	/**
	 * @var int
	 */
	public $total;

	public $data = array();

	/**
	 * Prepare search results display.
	 *
	 * @return void
	 */
	protected function before()
	{
		parent::before();

		require_once KPATH_SITE . '/models/search.php';
		$this->model = new KunenaModelSearch;
		$this->state = $this->model->getState();

		$this->me = KunenaUserHelper::getMyself();
		$this->message_ordering = $this->me->getMessageOrdering();

		$this->searchwords = $this->model->getSearchWords();
		$this->isModerator = ($this->me->isAdmin() || KunenaAccess::getInstance()->getModeratorStatus());

		$this->results = array();
		$this->total = $this->model->getTotal();
		$this->data = $this->model->getResults();

		$state = $this->state->get('list.start');
		$total = $this->total;
		$count = $this->data->count;

		

		$this->pagination = new KunenaPagination(
			$this->total, 
			$this->state->get('list.start'), 
			$this->data->count
		);

		$this->error = $this->model->getError();
	}

	/**
	 * Prepare document.
	 *
	 * @return void
	 */
	protected function prepareDocument()
	{
		$this->setTitle(JText::_('COM_KUNENA_SEARCH_ADVSEARCH'));
	}
}
