<?php

use Psr\Log\NullLogger;
use Thruway\Event\EventDispatcher;
use Thruway\Event\MessageEvent;
use Thruway\Event\NewRealmEvent;
use Thruway\Logging\Logger;
use Thruway\Message\ErrorMessage;
use Thruway\Message\EventMessage;
use Thruway\Message\HelloMessage;
use Thruway\Message\Message;
use Thruway\Message\SubscribedMessage;
use Thruway\Message\SubscribeMessage;
use Thruway\Message\UnsubscribedMessage;
use Thruway\Message\UnsubscribeMessage;
use Thruway\Message\WelcomeMessage;
use Thruway\Module\SubscriptionMetaModule;
use Thruway\Realm;
use Thruway\Session;
use Thruway\Transport\DummyTransport;
use Thruway\Transport\TransportInterface;

class SubscriptionMetaModuleTest extends PHPUnit_Framework_TestCase
{
    public function setup()
    {
        Logger::set(new NullLogger());
    }

    public function testSomething()
    {
        $realm = new Realm('some_realm');
        $module = new SubscriptionMetaModule();
        $realm->addModule($module);

        /** @var TransportInterface $listenTransport */
        $listenTransport = $this->getMockBuilder(TransportInterface::class)
            ->setMethods(['sendMessage'])
            ->getMockForAbstractClass();
        $sessionListener = new Session($listenTransport);
        $sessionListener->setHelloMessage(new HelloMessage('some_realm', (object)['roles'=>(object)[]]));

        $realm->addSession($sessionListener);

        $listenedMessages = [];
        $listenTransport->expects($this->any())
            ->method('sendMessage')
            ->will(
                $this->returnCallback(
                    function (Message $message) use (&$listenedMessages) {
                        $listenedMessages[] = $message;
                    }
                )
            );

        $otherTransport = new DummyTransport();
        $otherSession   = new Session($otherTransport);
        $otherSession->setHelloMessage(new HelloMessage('some_realm', (object)['roles'=>(object)[]]));
        $realm->addSession($otherSession);

        // now the testing...
        $sessionListener->dispatchMessage($sessionListener->getHelloMessage());
        $otherSession->dispatchMessage($otherSession->getHelloMessage());

        $this->assertInstanceOf(WelcomeMessage::class, array_pop($listenedMessages));
        $this->assertEmpty($listenedMessages);

        $otherSession->dispatchMessage(new SubscribeMessage(1234, [], 'some_topic_no_meta'));
        /** @var SubscribedMessage $noMetaSubMsg */
        $noMetaSubMsg = $otherTransport->getLastMessageSent();
        $noMetaSubId = $noMetaSubMsg->getSubscriptionId();

        $sessionListener->dispatchMessage(new SubscribeMessage(5678, ['match'=>'prefix'], 'wamp.subscription.'));

        $subscribedMsg = array_shift($listenedMessages);
        $this->assertInstanceOf(SubscribedMessage::class, $subscribedMsg);

        // immediate subscription events from our own subscription
        $this->assertEventMessageWithTopic('wamp.subscription.on_create', array_shift($listenedMessages));
        $this->assertEventMessageWithTopic('wamp.subscription.on_subscribe', array_shift($listenedMessages));
        $this->assertEmpty($listenedMessages);

        $otherSession->dispatchMessage(new SubscribeMessage(2345, [], 'some_topic'));

        $someTopicSubId = $otherTransport->getLastMessageSent()->getSubscriptionId();

        /** @var EventMessage $message */
        $message = array_shift($listenedMessages);
        $this->assertEquals('wamp.subscription.on_create', $message->getDetails()->topic);
        $this->assertEquals($otherSession->getSessionId(), $message->getArguments()[0]);
        $this->assertEquals($otherTransport->getLastMessageSent()->getSubscriptionId(), $message->getArguments()[1]->id);
        $this->assertEquals($someTopicSubId, $message->getArguments()[1]->id);
        $this->assertEquals("some_topic", $message->getArguments()[1]->uri);
        $this->assertEquals("exact", $message->getArguments()[1]->match);

        $message = array_shift($listenedMessages);
        $this->assertEquals('wamp.subscription.on_subscribe', $message->getDetails()->topic);
        $this->assertEquals($otherSession->getSessionId(), $message->getArguments()[0]);
        $this->assertEquals($otherTransport->getLastMessageSent()->getSubscriptionId(), $message->getArguments()[1]);

        $otherSession->dispatchMessage(new UnsubscribeMessage(3456, $someTopicSubId));
        /** @var UnsubscribedMessage $unSubedMsg */
        $unSubedMsg = $otherTransport->getLastMessageSent();
        $this->assertInstanceOf(UnsubscribedMessage::class, $unSubedMsg);

        $message = array_shift($listenedMessages);
        $this->assertEquals('wamp.subscription.on_unsubscribe', $message->getDetails()->topic);
        $this->assertEquals($otherSession->getSessionId(), $message->getArguments()[0]);
        $this->assertEquals($someTopicSubId, $message->getArguments()[1]);

        $message = array_shift($listenedMessages);
        $this->assertEquals('wamp.subscription.on_delete', $message->getDetails()->topic);
        $this->assertEquals($otherSession->getSessionId(), $message->getArguments()[0]);
        $this->assertEquals($someTopicSubId, $message->getArguments()[1]);

        $reflect = new ReflectionClass($module);
        $prop = $reflect->getProperty('unsubReqs');
        $prop->setAccessible(true);
        $this->assertCount(0, $prop->getValue($module));
        $prop->setAccessible(false);

        $reflect = new ReflectionClass($module);
        $prop = $reflect->getProperty('sessionSubs');
        $prop->setAccessible(true);
        // each of the 2 sessions still have a subscription
        $this->assertEquals([
            $sessionListener->getSessionId() => [
                $subscribedMsg->getSubscriptionId()
            ],
            $otherSession->getSessionId() => [
                $noMetaSubId
            ]
        ], $prop->getValue($module));
        $prop->setAccessible(false);

        // dropped session simulation
        $otherSession->onClose();

        $reflect = new ReflectionClass($module);
        $prop = $reflect->getProperty('sessionSubs');
        $prop->setAccessible(true);
        // each of the 2 sessions still have a subscription
        $this->assertEquals([
            $sessionListener->getSessionId() => [
                $subscribedMsg->getSubscriptionId()
            ]
        ], $prop->getValue($module));
        $prop->setAccessible(false);
    }

    private function assertEventMessageWithTopic($expectedTopic, EventMessage $message)
    {
        $this->assertEquals($expectedTopic, $message->getDetails()->topic);
    }

    public function testAddsModuleToRealmOnRealmCreate()
    {
        $eventDispatcher = new EventDispatcher();
        $module = new SubscriptionMetaModule();

        $eventDispatcher->addSubscriber($module);

        $realm = $this->getMockBuilder(Realm::class)
            ->disableOriginalConstructor()
            ->setMethods(['addModule'])
            ->getMock();

        $realm->expects($this->once())
            ->method('addModule')
            ->with($this->callback(function ($addedModule) use ($module) {
                return $addedModule === $module;
            }));

        $eventDispatcher->dispatch('new_realm', new NewRealmEvent($realm));
    }

    public function testRemovingAllSubscriptionsRemovesRefs()
    {
        $realm = new Realm('some_realm');
        $module = new SubscriptionMetaModule();
        $realm->addModule($module);

        /** @var TransportInterface $listenTransport */
        $listenTransport = $this->getMockBuilder(TransportInterface::class)
            ->setMethods(['sendMessage'])
            ->getMockForAbstractClass();
        $sessionListener = new Session($listenTransport);
        $sessionListener->setHelloMessage(new HelloMessage('some_realm', (object)['roles'=>(object)[]]));

        $realm->addSession($sessionListener);

        $listenedMessages = [];
        $listenTransport->expects($this->any())
            ->method('sendMessage')
            ->will(
                $this->returnCallback(
                    function (Message $message) use (&$listenedMessages) {
                        $listenedMessages[] = $message;
                    }
                )
            );

        $otherTransport = new DummyTransport();
        $otherSession   = new Session($otherTransport);
        $otherSession->setHelloMessage(new HelloMessage('some_realm', (object)['roles'=>(object)[]]));
        $realm->addSession($otherSession);

        // now the testing...
        $sessionListener->dispatchMessage($sessionListener->getHelloMessage());
        $otherSession->dispatchMessage($otherSession->getHelloMessage());

        $this->assertInstanceOf(WelcomeMessage::class, array_pop($listenedMessages));
        $this->assertEmpty($listenedMessages);

        $otherSession->dispatchMessage(new SubscribeMessage(1234, [], 'some_topic_no_meta'));
        /** @var SubscribedMessage $noMetaSubMsg */
        $noMetaSubMsg = $otherTransport->getLastMessageSent();
        $noMetaSubId = $noMetaSubMsg->getSubscriptionId();

        $sessionListener->dispatchMessage(new SubscribeMessage(5678, ['match'=>'prefix'], 'wamp.subscription.'));

        $subscribedMsg = array_shift($listenedMessages);
        $this->assertInstanceOf(SubscribedMessage::class, $subscribedMsg);

        // immediate subscription events from our own subscription
        $this->assertEventMessageWithTopic('wamp.subscription.on_create', array_shift($listenedMessages));
        $this->assertEventMessageWithTopic('wamp.subscription.on_subscribe', array_shift($listenedMessages));
        $this->assertEmpty($listenedMessages);

        $otherSession->dispatchMessage(new SubscribeMessage(2345, [], 'some_topic'));

        $someTopicSubId = $otherTransport->getLastMessageSent()->getSubscriptionId();

        /** @var EventMessage $message */
        $message = array_shift($listenedMessages);
        $this->assertEquals('wamp.subscription.on_create', $message->getDetails()->topic);
        $this->assertEquals($otherSession->getSessionId(), $message->getArguments()[0]);
        $this->assertEquals($otherTransport->getLastMessageSent()->getSubscriptionId(), $message->getArguments()[1]->id);
        $this->assertEquals($someTopicSubId, $message->getArguments()[1]->id);
        $this->assertEquals("some_topic", $message->getArguments()[1]->uri);
        $this->assertEquals("exact", $message->getArguments()[1]->match);

        $message = array_shift($listenedMessages);
        $this->assertEquals('wamp.subscription.on_subscribe', $message->getDetails()->topic);
        $this->assertEquals($otherSession->getSessionId(), $message->getArguments()[0]);
        $this->assertEquals($otherTransport->getLastMessageSent()->getSubscriptionId(), $message->getArguments()[1]);

        $otherSession->dispatchMessage(new UnsubscribeMessage(3456, $someTopicSubId));
        /** @var UnsubscribedMessage $unSubedMsg */
        $unSubedMsg = $otherTransport->getLastMessageSent();
        $this->assertInstanceOf(UnsubscribedMessage::class, $unSubedMsg);

        $message = array_shift($listenedMessages);
        $this->assertEquals('wamp.subscription.on_unsubscribe', $message->getDetails()->topic);
        $this->assertEquals($otherSession->getSessionId(), $message->getArguments()[0]);
        $this->assertEquals($someTopicSubId, $message->getArguments()[1]);

        $message = array_shift($listenedMessages);
        $this->assertEquals('wamp.subscription.on_delete', $message->getDetails()->topic);
        $this->assertEquals($otherSession->getSessionId(), $message->getArguments()[0]);
        $this->assertEquals($someTopicSubId, $message->getArguments()[1]);

        $reflect = new ReflectionClass($module);
        $prop = $reflect->getProperty('unsubReqs');
        $prop->setAccessible(true);
        $this->assertCount(0, $prop->getValue($module));
        $prop->setAccessible(false);

        $reflect = new ReflectionClass($module);
        $prop = $reflect->getProperty('sessionSubs');
        $prop->setAccessible(true);
        // each of the 2 sessions still have a subscription
        $this->assertEquals([
            $sessionListener->getSessionId() => [
                $subscribedMsg->getSubscriptionId()
            ],
            $otherSession->getSessionId() => [
                $noMetaSubId
            ]
        ], $prop->getValue($module));
        $prop->setAccessible(false);

        // remove the last subscription
        $otherSession->dispatchMessage(new UnsubscribeMessage(9876, $noMetaSubId));

        $message = array_shift($listenedMessages);
        $this->assertEquals('wamp.subscription.on_unsubscribe', $message->getDetails()->topic);
        $this->assertEquals($otherSession->getSessionId(), $message->getArguments()[0]);
        $this->assertEquals($noMetaSubId, $message->getArguments()[1]);

        $message = array_shift($listenedMessages);
        $this->assertEquals('wamp.subscription.on_delete', $message->getDetails()->topic);
        $this->assertEquals($otherSession->getSessionId(), $message->getArguments()[0]);
        $this->assertEquals($noMetaSubId, $message->getArguments()[1]);

        $reflect = new ReflectionClass($module);
        $prop = $reflect->getProperty('unsubReqs');
        $prop->setAccessible(true);
        $this->assertCount(0, $prop->getValue($module));
        $prop->setAccessible(false);

        $reflect = new ReflectionClass($module);
        $prop = $reflect->getProperty('sessionSubs');
        $prop->setAccessible(true);
        // each of the 2 sessions still have a subscription
        $this->assertEquals([
            $sessionListener->getSessionId() => [
                $subscribedMsg->getSubscriptionId()
            ]
        ], $prop->getValue($module));
        $prop->setAccessible(false);

        // now disconnect to see if it handles leaving when there are no subscriptions
        $otherSession->onClose();

        $reflect = new ReflectionClass($module);
        $prop = $reflect->getProperty('sessionSubs');
        $prop->setAccessible(true);
        // each of the 2 sessions still have a subscription
        $this->assertEquals([
            $sessionListener->getSessionId() => [
                $subscribedMsg->getSubscriptionId()
            ]
        ], $prop->getValue($module));
        $prop->setAccessible(false);
    }

    public function testUnsubscribedWithoutSubscribed()
    {
        $session = $this->createMock(Session::class);

        $module = new SubscriptionMetaModule();
        $module->handleUnsubscribedMessage(
            new MessageEvent($session, new UnsubscribedMessage(456)));
    }

    public function testHandleErrorMessage()
    {
        $session = new Session(new DummyTransport());

        $module = new SubscriptionMetaModule();
        $module->handleUnsubscribeMessage(new MessageEvent(
            $session,
            new UnsubscribeMessage(123, 456)
        ));

        $reflect = new ReflectionClass($module);
        $prop = $reflect->getProperty('unsubReqs');
        $prop->setAccessible(true);
        $this->assertEquals([
            $session->getSessionId() . '-' . 123 => 456
        ], $prop->getValue($module));
        $prop->setAccessible(false);


        $module->handleErrorMessage(new MessageEvent(
            $session,
            new ErrorMessage(
                UnsubscribeMessage::MSG_UNSUBSCRIBE,
                123,
                [],
                'some.error'
            )
        ));

        $reflect = new ReflectionClass($module);
        $prop = $reflect->getProperty('unsubReqs');
        $prop->setAccessible(true);
        $this->assertEquals([], $prop->getValue($module));
        $prop->setAccessible(false);
    }
}
