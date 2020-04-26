<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * @author Mike Shatunov <mixasic@yandex.ru>
 */
class m200310_124849_ref_home_treatment_profile extends Migration {
	private const TABLE_NAME = 'ref_home_treatment_profile';
	/**
	 * {@inheritdoc}
	 *
	 * @author Mike Shatunov <mixasic@yandex.ru>
	 */
	public function safeUp() {
		$this->createTable(static::TABLE_NAME, [
			'id'			=> 'UUID NOT NULL DEFAULT uuid_generate_v4()',
			'home_id'		=> 'UUID NOT NULL',
			'name'			=> 'TEXT NOT NULL',
			'insert_stamp'	=> 'TIMESTAMP NOT NULL DEFAULT TIMEZONE(\'UTC\', NOW())',
			'update_stamp'	=> 'TIMESTAMP NOT NULL DEFAULT TIMEZONE(\'UTC\', NOW())',
			'delete_stamp'	=> 'TIMESTAMP NOT NULL DEFAULT \'0001-01-01 00:00:00 \'',
		]);

		$this->addPrimaryKey('pk-ref_home_treatment_profile', static::TABLE_NAME, ['id']);

		$this->addForeignKey('fk-ref_home_treatment_profile[home]', static::TABLE_NAME, 'home_id', 'ref_home', 'id', 'restrict', 'restrict');

		$this->addCommentOnColumn(static::TABLE_NAME, 'id', 'Идентификатор профиля лечения');
		$this->addCommentOnColumn(static::TABLE_NAME, 'home_id', 'Идентификатор объекта размещения');
		$this->addCommentOnColumn(static::TABLE_NAME, 'name', 'Наименование профиля лечения');
		$this->addCommentOnColumn(static::TABLE_NAME, 'insert_stamp', 'Дата и время добавления');
		$this->addCommentOnColumn(static::TABLE_NAME, 'update_stamp', 'Дата и время обновления');
		$this->addCommentOnColumn(static::TABLE_NAME, 'delete_stamp', 'Дата и время удаления');

		$this->addCommentOnTable(static::TABLE_NAME, 'Профили лечения');

		// Изменение связей. Типы болезней к профилям лечения. Профили лечения к программам лечения.
		$this->truncateTable('ref_home_treatment_program_lnk_disease');
		$this->renameTable('ref_home_treatment_program_lnk_disease', 'ref_home_treatment_program_lnk_profile');
		$this->dropForeignKey('fk-ref_home_treatment_program_lnk_disease[disease]', 'ref_home_treatment_program_lnk_profile');
		$this->renameColumn('ref_home_treatment_program_lnk_profile', 'disease_id', 'profile_id');
		$this->addForeignKey('fk-ref_home_treatment_program_lnk_profile[profile]', 'ref_home_treatment_program_lnk_profile', 'profile_id', static::TABLE_NAME, 'id', 'restrict', 'restrict');
	}

	/**
	 * {@inheritdoc}
	 *
	 * @author Mike Shatunov <mixasic@yandex.ru>
	 */
	public function safeDown() {
		$this->dropTable(static::TABLE_NAME);
	}
}