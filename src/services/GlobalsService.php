<?php
namespace Craft;

/**
 *
 */
class GlobalsService extends BaseApplicationComponent
{
	private $_allGlobalSetIds;
	private $_editableGlobalSetIds;
	private $_allGlobalSets;
	private $_globalSetsById;

	/**
	 * Returns all of the global set IDs.
	 *
	 * @return array
	 */
	public function getAllSetIds()
	{
		if (!isset($this->_allGlobalSetIds))
		{
			$this->_allGlobalSetIds = craft()->db->createCommand()
				->select('id')
				->from('globalsets')
				->queryColumn();
		}

		return $this->_allGlobalSetIds;
	}

	/**
	 * Returns all of the global set IDs that are editable by the current user.
	 *
	 * @return array
	 */
	public function getEditableSetIds()
	{
		if (!isset($this->_editableGlobalSetIds))
		{
			$this->_editableGlobalSetIds = array();
			$allGlobalSetIds = $this->getAllSetIds();

			foreach ($allGlobalSetIds as $globalSetId)
			{
				if (craft()->userSession->checkPermission('editGlobalSet:'.$globalSetId))
				{
					$this->_editableGlobalSetIds[] = $globalSetId;
				}
			}
		}

		return $this->_editableGlobalSetIds;
	}

	/**
	 * Returns all global sets.
	 *
	 * @param string|null $indexBy
	 * @return array
	 */
	public function getAllSets($indexBy = null)
	{
		if (!isset($this->_allGlobalSets))
		{
			$criteria = craft()->elements->getCriteria(ElementType::GlobalSet);
			$this->_allGlobalSets = $criteria->find();

			// Index them by ID
			foreach ($this->_allGlobalSets as $globalSet)
			{
				$this->_globalSetsById[$globalSet->id] = $globalSet;
			}
		}

		if (!$indexBy)
		{
			return $this->_allGlobalSets;
		}
		else
		{
			$globalSets = array();

			foreach ($this->_allGlobalSets as $globalSet)
			{
				$globalSets[$globalSet->$indexBy] = $globalSet;
			}
		}

		return $globalSets;
	}

	/**
	 * Returns all global sets that are editable by the current user.
	 *
	 * @param string|null $indexBy
	 * @return array
	 */
	public function getEditableSets($indexBy = null)
	{
		$globalSets = $this->getAllSets();
		$editableGlobalSetIds = $this->getEditableSetIds();
		$editableGlobalSets = array();

		foreach ($globalSets as $globalSet)
		{
			if (in_array($globalSet->id, $editableGlobalSetIds))
			{
				if ($indexBy)
				{
					$editableGlobalSets[$globalSet->$indexBy] = $globalSet;
				}
				else
				{
					$editableGlobalSets[] = $globalSet;
				}
			}
		}

		return $editableGlobalSets;
	}

	/**
	 * Returns the total number of global sets.
	 *
	 * @return int
	 */
	public function getTotalSets()
	{
		return count($this->getAllSetIds());
	}

	/**
	 * Returns the total number of global sets that are editable by the current user.
	 *
	 * @return int
	 */
	public function getTotalEditableSets()
	{
		return count($this->getEditableSetIds());
	}

	/**
	 * Returns a global set by its ID.
	 *
	 * @param $globalSetId
	 * @return GlobalSetModel|null
	 */
	public function getSetById($globalSetId)
	{
		if (!isset($this->_allGlobalSets))
		{
			$this->getAllSets();
		}

		if (isset($this->_globalSetsById[$globalSetId]))
		{
			return $this->_globalSetsById[$globalSetId];
		}
	}

	/**
	 * Saves a global set.
	 *
	 * @param GlobalSetModel $globalSet
	 * @throws \Exception
	 * @return bool
	 */
	public function saveSet(GlobalSetModel $globalSet)
	{
		$isNewSet = !$globalSet->id;

		if (!$isNewSet)
		{
			$globalSetRecord = GlobalSetRecord::model()->with('element')->findById($globalSet->id);

			if (!$globalSetRecord)
			{
				throw new Exception(Craft::t('No global set exists with the ID “{id}”', array('id' => $globalSet->id)));
			}

			$oldSet = GlobalSetModel::populateModel($globalSetRecord);
			$elementRecord = $globalSetRecord->element;
		}
		else
		{
			$globalSetRecord = new GlobalSetRecord();

			$elementRecord = new ElementRecord();
			$elementRecord->type = ElementType::GlobalSet;
		}

		$globalSetRecord->name   = $globalSet->name;
		$globalSetRecord->handle = $globalSet->handle;
		$globalSetRecord->validate();
		$globalSet->addErrors($globalSetRecord->getErrors());

		$elementRecord->enabled = $globalSet->enabled;
		$elementRecord->validate();
		$globalSet->addErrors($elementRecord->getErrors());

		if (!$globalSet->hasErrors())
		{
			$transaction = craft()->db->getCurrentTransaction() === null ? craft()->db->beginTransaction() : null;
			try
			{
				if (!$isNewSet && $oldSet->fieldLayoutId)
				{
					// Drop the old field layout
					craft()->fields->deleteLayoutById($oldSet->fieldLayoutId);
				}

				// Save the new one
				$fieldLayout = $globalSet->getFieldLayout();
				craft()->fields->saveLayout($fieldLayout, false);

				// Update the set record/model with the new layout ID
				$globalSet->fieldLayoutId = $fieldLayout->id;
				$globalSetRecord->fieldLayoutId = $fieldLayout->id;

				// Save the element record first
				$elementRecord->save(false);

				if ($isNewSet)
				{
					// Now that we have an element ID, save it on the other stuff
					$globalSet->id = $elementRecord->id;
					$globalSetRecord->id = $globalSet->id;

					// Create a new row in elements_i18n
					$elementLocaleRecord = new ElementLocaleRecord();
					$elementLocaleRecord->elementId = $globalSet->id;
					$elementLocaleRecord->locale    = $globalSet->locale;
					$elementLocaleRecord->save();

					// Create a row in content
					$content = new ContentModel();
					$content->elementId = $globalSet->id;
					craft()->content->saveContent($content);
				}

				$globalSetRecord->save(false);

				if ($transaction !== null)
				{
					$transaction->commit();
				}
			}
			catch (\Exception $e)
			{
				if ($transaction !== null)
				{
					$transaction->rollback();
				}

				throw $e;
			}

			return true;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Deletes a global set by its ID.
	 *
	 * @param int $setId
	 * @throws \Exception
	 * @return bool
	*/
	public function deleteSetById($setId)
	{
		if (!$setId)
		{
			return false;
		}

		$transaction = craft()->db->getCurrentTransaction() === null ? craft()->db->beginTransaction() : null;
		try
		{
			// Delete the field layout
			$fieldLayoutId = craft()->db->createCommand()
				->select('fieldLayoutId')
				->from('globalsets')
				->where(array('id' => $setId))
				->queryScalar();

			if ($fieldLayoutId)
			{
				craft()->fields->deleteLayoutById($fieldLayoutId);
			}

			$affectedRows = craft()->elements->deleteElementById($setId);

			if ($transaction !== null)
			{
				$transaction->commit();
			}

			return (bool) $affectedRows;
		}
		catch (\Exception $e)
		{
			if ($transaction !== null)
			{
				$transaction->rollback();
			}

			throw $e;
		}
	}

	/**
	 * Saves a global set's content
	 *
	 * @param GlobalSetModel $globalSet
	 * @return bool
	 */
	public function saveContent(GlobalSetModel $globalSet)
	{
		if (craft()->content->saveElementContent($globalSet, $globalSet->getFieldLayout()))
		{
			// Create the elements_i18n row if it doesn't exist
			$elementLocaleRecord = ElementLocaleRecord::model()->findByAttributes(array(
				'elementId' => $globalSet->id,
				'locale'    => $globalSet->locale
			));

			if (!$elementLocaleRecord)
			{
				$elementLocaleRecord = new ElementLocaleRecord();
				$elementLocaleRecord->elementId = $globalSet->id;
				$elementLocaleRecord->locale    = $globalSet->locale;
				$elementLocaleRecord->save();
			}

			// Fire an 'onSaveGlobalContent' event
			$this->onSaveGlobalContent(new Event($this, array(
				'globalSet' => $globalSet
			)));

			return true;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Fires an 'onSaveGlobalContent' event.
	 *
	 * @param Event $event
	 */
	public function onSaveGlobalContent(Event $event)
	{
		$this->raiseEvent('onSaveGlobalContent', $event);
	}
}
