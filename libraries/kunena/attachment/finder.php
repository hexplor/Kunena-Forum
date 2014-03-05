<?php
/**
 * Kunena Component
 * @package Kunena.Framework
 * @subpackage Attachment
 *
 * @copyright (C) 2008 - 2013 Kunena Team. All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link http://www.kunena.org
 **/
defined ( '_JEXEC' ) or die ();

/**
 * Class KunenaAttachmentFinder
 */
class KunenaAttachmentFinder extends KunenaDatabaseObjectFinder
{
	protected $table = '#__kunena_attachments';

	/**
	 * Get log entries.
	 *
	 * @return array|KunenaCollection
	 */
	public function find()
	{
		if ($this->skip)
		{
			return array();
		}

		$query = clone $this->query;
		$this->build($query);
		$query->select('a.*');
		$this->db->setQuery($query, $this->start, $this->limit);
		$results = new KunenaCollection((array) $this->db->loadObjectList('id', 'KunenaAttachment'));
		KunenaError::checkDatabaseError();

		return $results;
	}
}
