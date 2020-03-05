<?php
/**
 * Copyright 2015 - 2016, Cake Development Corporation (http://cakedc.com)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright 2015 - 2016, Cake Development Corporation (http://cakedc.com)
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */

namespace CakeDC\OracleDriver\Test\TestCase\ORM;

use Cake\Database\Expression\IdentifierExpression;
use Cake\Database\Expression\QueryExpression;
use Cake\I18n\Time;
use Cake\ORM\TableRegistry;
use Cake\Test\TestCase\ORM\QueryRegressionTest as CakeQueryRegressionTest;

/**
 * Tests QueryRegression class
 *
 */
class QueryRegressionTest extends CakeQueryRegressionTest
{

    /**
     * Test expression based ordering with unions.
     *
     * @return void
     */
    public function testComplexOrderWithUnion()
    {
        $this->loadFixtures('Comments');
        $table = TableRegistry::get('Comments');
        $query = $table->find();
        $inner = $table->find()
           ->select(['content' => 'to_char(comment)'])
           ->where(['id >' => 3]);
        $inner2 = $table->find()
            ->select(['content' => 'to_char(comment)'])
            ->where(['id <' => 3]);

        $order = $query->func()
               ->concat(['content' => 'identifier', 'test']);

        $query->select(['inside.content'])
              ->from(['inside' => $inner->unionAll($inner2)])
              ->orderAsc($order);

        $results = $query->toArray();
        $this->assertCount(5, $results);
    }

    /**
     * Test that save() works with entities containing expressions
     * as properties.
     *
     * @return void
     */
    public function testSaveWithExpressionProperty()
    {
        $this->loadFixtures('Articles');
        $articles = TableRegistry::get('Articles');
        $article = $articles->newEntity();
        $article->title = new \Cake\Database\Expression\QueryExpression("SELECT 'jose' from DUAL");
        $this->assertSame($article, $articles->save($article));
    }

    /**
     * Tests that using the subquery strategy in a deep association returns the right results
     *
     * @see https://github.com/cakephp/cakephp/issues/4484
     * @return void
     */
    public function testDeepBelongsToManySubqueryStrategy()
    {
        $this->loadFixtures('Authors', 'Tags', 'Articles', 'ArticlesTags');
        $table = TableRegistry::get('Authors');
        $table->hasMany('Articles');
        $table->Articles->belongsToMany('Tags', [
            'strategy' => 'subquery'
        ]);

        $result = $table->find()
            ->contain([
                'Articles' => [
                    'Tags' => function ($q) {
                        return $q->order(['name']);
                    }
                ]
            ])
            ->toArray();
        $this->assertEquals(['tag1', 'tag3'], collection($result[2]->articles[0]->tags)->extract('name')->toArray());
    }

    /**
     * Tests that using the subquery strategy in a deep association returns the right results
     *
     * @see https://github.com/cakephp/cakephp/issues/5769
     * @return void
     */
    public function testDeepBelongsToManySubqueryStrategy2()
    {
        $this->loadFixtures('Authors', 'Tags', 'AuthorsTags', 'Articles', 'ArticlesTags');
        $table = TableRegistry::get('Authors');
        $table->hasMany('Articles');
        $table->Articles->belongsToMany('Tags', [
            'strategy' => 'subquery'
        ]);
        $table->belongsToMany('Tags', [
            'strategy' => 'subquery',
        ]);
        $table->Articles->belongsTo('Authors');

        $result = $table->Articles
            ->find()
          ->where(['Authors.id >' => 1])
          ->contain([
              'Authors' => [
                  'Tags' => function ($q) {
                      return $q->order(['name']);
                  }
              ]
          ])
          ->toArray();
        $this->assertEquals(['tag1', 'tag2'], collection($result[0]->author->tags)
            ->extract('name')
            ->toArray());
        $this->assertEquals(3, $result[0]->author->id);
    }

    /**
     * such syntax is not supported and leads to ORA-00937
     */
    public function testSubqueryInSelectExpression()
    {
    }

    /**
     * Tests that getting the count of a query with bind is correct
     *
     * @see https://github.com/cakephp/cakephp/issues/8466
     * @return void
     */
    public function testCountWithBind()
    {
        $this->loadFixtures('Articles');
        $table = $this->getTableLocator()->get('Articles');

        $query = $table
            ->find()
            ->select(['title', 'id'])
            ->where(function($exp) {
                return $exp->like(new IdentifierExpression('title'), ':c0');
            })
            ->group(['id', 'title'])
            ->bind(':c0', '%Second%');
        $count = $query->count();
        $this->assertEquals(1, $count);
    }

    /**
     * Tests that bind in subqueries works.
     *
     * @return void
     */
    public function testSubqueryBind()
    {
        $this->loadFixtures('Articles');
        $table = $this->getTableLocator()->get('Articles');
        $sub = $table->find()
            ->select(['id'])
            ->where(function($exp) {
                return $exp->like(new IdentifierExpression('title'), ':c0');
            })
            ->bind(':c0', 'Second %');

        $query = $table
            ->find()
            ->select(['title'])
            ->where(function($exp) use ($sub) {
                $e = new QueryExpression();
                return $exp->add($e->notIn(new IdentifierExpression('id'), $sub));
            });
        $result = $query->toArray();
        $this->assertCount(2, $result);
        $this->assertEquals('First Article', $result[0]->title);
        $this->assertEquals('Third Article', $result[1]->title);
    }

    /**
     * Test selecting with aliased aggregates and identifier quoting
     * does not emit notice errors.
     *
     * @see https://github.com/cakephp/cakephp/issues/12766
     * @return void
     */
    public function testAliasedAggregateFieldTypeConversionSafe()
    {
        $this->loadFixtures('Articles');
        $articles = $this->getTableLocator()->get('Articles');

        $driver = $articles->getConnection()->getDriver();
        $restore = $driver->isAutoQuotingEnabled();

        $driver->enableAutoQuoting(true);
        $query = $articles->find();
        $query->select([
            'sumUsers' => $articles->find()->func()->sum(new IdentifierExpression('author_id'))
        ]);
        $driver->enableAutoQuoting($restore);

        $result = $query->execute()->fetchAll('assoc');
        $this->assertArrayHasKey('sumUsers', $result[0]);
    }

    /**
     * Test that the typemaps used in function expressions
     * create the correct results.
     *
     * @return void
     */
    public function testTypemapInFunctions2()
    {
        $this->loadFixtures('Comments');
        $table = $this->getTableLocator()->get('Comments');
        $query = $table->find();
        $query->select([
            'max' => $query->func()->max(new IdentifierExpression('created'), ['datetime'])
        ]);
        $result = $query->all()->first();
        $this->assertEquals(new Time('2007-03-18 10:55:23'), $result['max']);
    }

    /**
     * We can use only union all with queries with clob fields
     *
     * @see https://asktom.oracle.com/pls/apex/f?p=100:11:0::::P11_QUESTION_ID:498299691850
     * @return void
     */
    public function testCountWithUnionQuery()
    {
        $this->loadFixtures('Articles');
        $table = TableRegistry::get('Articles');
        $query = $table->find()
                       ->where(['id' => 1]);
        $query2 = $table->find()
                        ->where(['id' => 2]);
        $query->unionAll($query2);
        $this->assertEquals(2, $query->count());

        $fields = [
            'id',
            'author_id',
            'title',
            'body' => 'to_char(body)',
            'published'
        ];
        $query = $table->find()
                       ->select($fields)
                       ->where(['id' => 1]);
        $query2 = $table->find()
                        ->select($fields)
                        ->where(['id' => 2]);
        $query->union($query2);
        $this->assertEquals(2, $query->count());
    }

}
