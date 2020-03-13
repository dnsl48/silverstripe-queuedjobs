<?php

namespace Symbiote\QueuedJobs\Tests;

use SilverStripe\Core\Config\Config;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextareaField;
use SilverStripe\ORM\FieldType\DBDatetime;
use Symbiote\QueuedJobs\Controllers\QueuedJobsAdmin;
use Symbiote\QueuedJobs\Jobs\PublishItemsJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Symbiote\QueuedJobs\Tests\Jobs\TestDummyJob;

/**
 * Tests for the QueuedJobsAdmin ModelAdmin clas
 *
 * @coversDefaultClass \Symbiote\QueuedJobs\Controllers\QueuedJobsAdmin
 * @package queuedjobs
 * @author  Robbie Averill <robbie@silverstripe.com>
 */
class QueuedJobsAdminTest extends FunctionalTest
{
    /**
     * {@inheritDoc}
     * @var string
     */
    // protected static $fixture_file = 'QueuedJobsAdminTest.yml';

    protected $usesDatabase = true;

    /**
     * @var QueuedJobsAdmin
     */
    protected $admin;

    /**
     * Get a test class, and mock the job queue for it
     *
     * {@inheritDoc}
     */
    protected function setUp()
    {
        parent::setUp();

        // The shutdown handler doesn't play nicely with SapphireTest's database handling
        QueuedJobService::config()->set('use_shutdown_function', false);

        $this->admin = new QueuedJobsAdmin();
        $request = new HTTPRequest('GET', '/');
        $request->setSession($this->session());
        Injector::inst()->registerService($request, HTTPRequest::class);
        $this->admin->setRequest($request);

        $mockQueue = $this->createMock(QueuedJobService::class);
        $this->admin->jobQueue = $mockQueue;

        $this->logInWithPermission('ADMIN');
        $this->admin->doInit();
    }

    /**
     * Ensure that the JobParams field is added as a Textarea
     */
    public function testConstructorParamsShouldBeATextarea()
    {
        $fields = $this->admin->getEditForm('foo', new FieldList())->Fields();
        $this->assertInstanceOf(TextareaField::class, $fields->fieldByName('JobParams'));
    }

    /**
     * Ensure that when a multi-line value is entered for JobParams, it is split by new line and each value
     * passed to the constructor of the JobType that is created by the reflection in createjob()
     *
     * @covers ::createjob
     */
    public function testCreateJobWithConstructorParams()
    {
        $this->admin->jobQueue
            ->expects($this->once())
            ->method('queueJob')
            ->with($this->callback(function ($job) {
                $this->assertInstanceOf(TestDummyJob::class, $job);
                $this->assertEquals(['foo', 'bar', 'baz', 'qux'], $job->constructParams);

                return true;
            }));

        $form = $this->admin->getEditForm('foo', new FieldList());
        $form->Fields()->fieldByName('JobParams')->setValue("foo\nbar\rbaz\r\nqux");
        $form->Fields()->fieldByName('JobType')->setValue(TestDummyJob::class);

        $this->admin->createjob($form->getData(), $form);
    }

    /**
     * @covers ::createjob
     */
    public function testCreateJobWithStartAfterOption()
    {
        $startTimeAfter = DBDatetime::now();

        $this->admin->jobQueue
            ->expects($this->once())
            ->method('queueJob')
            ->with(
                $this->callback(static function ($job) {
                    return $job instanceof PublishItemsJob;
                }),
                $this->callback(static function ($givenStartAfter) use ($startTimeAfter) {
                    return $givenStartAfter === $startTimeAfter->forTemplate();
                })
            );

        $form = $this->admin->getEditForm('foo', new FieldList());
        $form->Fields()->fieldByName('JobType')->setValue(PublishItemsJob::class);
        $form->Fields()->fieldByName('JobStart')->setValue($startTimeAfter);

        $this->admin->createjob($form->getData(), $form);
    }
}
