<?php
namespace ModelHistoryTestApp\Model\Table;

use Cake\ORM\Query;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use ModelHistoryTestApp\Model\Entity\User;

/**
 * Users Model
 */
class UsersTable extends Table
{

    /**
     * Initialize method
     *
     * @param array $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config)
    {
        $this->table('users');
        $this->displayField('forename');
        $this->primaryKey('id');
        $this->addBehavior('Timestamp');
        $this->addBehavior('ModelHistory.Historizable', [
            'userIdCallback' => null,
            'fields' => [
                [
                    'name' => 'id',
                    'translation' => __('users.id'),
                    'searchable' => true,
                    'saveable' => true,
                    'obfuscated' => false,
                    'type' => 'string',
                    'displayParser' => null,
                    'saveParser' => null
                ],
                [
                    'name' => 'forename',
                    'translation' => __('users.forename'),
                    'searchable' => true,
                    'saveable' => true,
                    'obfuscated' => false,
                    'type' => 'string',
                    'displayParser' => null,
                    'saveParser' => null
                ],
                [
                    'name' => 'surname',
                    'translation' => __('users.surname'),
                    'searchable' => true,
                    'saveable' => true,
                    'obfuscated' => false,
                    'type' => 'string',
                    'displayParser' => null,
                    'saveParser' => null
                ]
            ]
        ]);
    }

    /**
     * Default validation rules.
     *
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator)
    {
        $validator
            ->add('id', 'valid', ['rule' => 'uuid'])
            ->allowEmpty('id', 'create')
            ->allowEmpty('forename')
            ->allowEmpty('surname');
        return $validator;
    }
}
