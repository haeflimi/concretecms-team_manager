<?php

namespace Concrete\Package\TeamManager\Attribute\TeamPool;

use Concrete\Core\Attribute\DefaultController;
use Concrete\Core\Attribute\FontAwesomeIconFormatter;
use TeamManager\Team\TeamPoolRepository;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * A team pool (its group ID as text): links a page, e.g. a tournament page, to the pool people sign up in.
 */
class Controller extends DefaultController
{
    public function getIconFormatter()
    {
        return new FontAwesomeIconFormatter('users');
    }

    public function form()
    {
        $options = ['' => t('** None')];
        foreach ($this->app->make(TeamPoolRepository::class)->getAll() as $pool) {
            $options[$pool->getID()] = $pool->getName();
        }
        $this->set('options', $options);
        $this->set('fieldName', $this->field('value'));
        $this->set('selected', $this->value());
    }

    public function createAttributeValueFromRequest()
    {
        $gID = (int) $this->post('value');

        return $this->createAttributeValue($gID > 0 ? (string) $gID : '');
    }

    public function createAttributeValue($value)
    {
        return parent::createAttributeValue((int) $value > 0 ? (string) (int) $value : '');
    }

    public function getDisplayValue()
    {
        $pool = (int) $this->value() > 0 ? $this->app->make(TeamPoolRepository::class)->getByID((int) $this->value()) : null;

        return $pool ? h($pool->getName()) : '';
    }

    public function validateForm($data)
    {
        return true;
    }

    public function validateValue()
    {
        return true;
    }

    public function search()
    {
    }

    protected function value(): string
    {
        return is_object($this->attributeValue) ? (string) $this->getAttributeValue()->getValue() : '';
    }
}
