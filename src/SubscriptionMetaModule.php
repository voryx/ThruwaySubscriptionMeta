<?php

namespace Thruway\Module;

// https://github.com/wamp-proto/wamp-proto/blob/master/rfc/text/advanced/ap_pubsub_subscription_meta_api.md
/*
 * This module implements the subscription meta api by treating every subscription as a single subscription
 * This makes it a little long-winded compared to the crossbar implementation - but Thruway
 * does not reuse subscription ids the same way
 */

use Thruway\Event\LeaveRealmEvent;
use Thruway\Event\MessageEvent;
use Thruway\Event\NewRealmEvent;
use Thruway\Logging\Logger;
use Thruway\Message\ErrorMessage;
use Thruway\Message\SubscribedMessage;
use Thruway\Message\UnsubscribedMessage;
use Thruway\Message\UnsubscribeMessage;
use Thruway\Realm;
use Thruway\Subscription\Subscription;

class SubscriptionMetaModule extends RouterModule implements RealmModuleInterface
{
    /** @var Realm[] */
    private $realms = [];

    /** @var array */
    private $unsubReqs = [];

    private $sessionSubs = [];

    public function handleLeaveRealm(LeaveRealmEvent $event)
    {
        if (!isset($this->sessionSubs[$event->session->getSessionId()])) {
            return;
        }

        while (!empty($this->sessionSubs[$event->session->getSessionId()])) {
            $this->doUnsubscribeMeta($event->realm, $event->session->getSessionId(), array_pop($this->sessionSubs[$event->session->getSessionId()]));
        }

        unset($this->sessionSubs[$event->session->getSessionId()]);
    }

    private function doUnsubscribeMeta(Realm $realm, $sessionId, $subscriptionId)
    {
        $args = [
            $sessionId,
            $subscriptionId
        ];

        $realm->publishMeta('wamp.subscription.on_unsubscribe', $args);

        $realm->publishMeta('wamp.subscription.on_delete', $args);
    }

    public function handleSendWelcomeMessage(MessageEvent $event)
    {

    }

    public function handleSubscribedMessage(MessageEvent $event)
    {
        /** @var SubscribedMessage $message */
        $message = $event->message;
        $realm = $event->session->getRealm();

        /** @var Subscription $subscription */
        $subscription = $realm->getBroker()->getSubscriptionById($message->getSubscriptionId());
        $realm->publishMeta(
            'wamp.subscription.on_create',
            [
                $event->session->getSessionId(),
                (object)[
                    'id' => $subscription->getId(),
                    'created' => (new \DateTimeImmutable())->format(DATE_ISO8601),
                    'uri' => $subscription->getUri(),
                    'match' => $subscription->getSubscriptionGroup()->getMatchType()
                ]
            ]);

        $realm->publishMeta(
            'wamp.subscription.on_subscribe',
            [
                $event->session->getSessionId(),
                $subscription->getId()
            ]);

        if (!isset($this->sessionSubs[$event->session->getSessionId()])) {
            $this->sessionSubs[$event->session->getSessionId()] = [];
        }

        $this->sessionSubs[$event->session->getSessionId()][] = $subscription->getId();
    }

    public function handleUnsubscribeMessage(MessageEvent $event)
    {
        /** @var UnsubscribeMessage $message */
        $message = $event->message;

        $this->unsubReqs[$event->session->getSessionId() . '-' . $message->getRequestId()] = $message->getSubscriptionId();
    }

    public function handleErrorMessage(MessageEvent $event)
    {
        /** @var ErrorMessage $message */
        $message = $event->message;

        if ($message->getErrorMsgCode() !== UnsubscribeMessage::MSG_UNSUBSCRIBE) {
            return;
        }

        unset($this->unsubReqs[$event->session->getSessionId() . '-' . $message->getRequestId()]);
    }

    public function handleUnsubscribedMessage(MessageEvent $event)
    {
        /** @var UnsubscribedMessage $message */
        $message = $event->message;

        if (!isset($this->unsubReqs[$event->session->getSessionId() . '-' . $message->getRequestId()])) {
            Logger::warning($this, 'Unsubscribed message seen without first seeing unsubscribe.');
            return;
        }

        $realm = $event->session->getRealm();

        $subscriptionId = $this->unsubReqs[$event->session->getSessionId() . '-' . $message->getRequestId()];
        unset($this->unsubReqs[$event->session->getSessionId() . '-' . $message->getRequestId()]);

        $this->doUnsubscribeMeta($realm, $event->session->getSessionId(), $subscriptionId);

        $this->sessionSubs[$event->session->getSessionId()] = array_filter(
            $this->sessionSubs[$event->session->getSessionId()],
            function ($sub) use ($subscriptionId) {
                return $sub !== $subscriptionId;
            });

        if (empty($this->sessionSubs[$event->session->getSessionId()])) {
            unset($this->sessionSubs[$event->session->getSessionId()]);
        }
    }

    public static function getSubscribedEvents()
    {
        return [
            "new_realm" => ["handleNewRealm", 10],
        ];
    }

    public function handleNewRealm(NewRealmEvent $event)
    {
        Logger::info($this, "Adding SubscriptionMetaModule to: ".$event->realm->getRealmName());
        $this->realms[$event->realm->getRealmName()] = $event->realm;

        $event->realm->addModule($this);
    }

    /** @return array */
    public function getSubscribedRealmEvents()
    {
        return [
            "LeaveRealm"                   => ["handleLeaveRealm", 20],
            "SendWelcomeMessageEvent"      => ["handleSendWelcomeMessage", 5],
            "SendSubscribedMessageEvent"   => ["handleSubscribedMessage", 5],
            "UnsubscribeMessageEvent"      => ["handleUnsubscribeMessage", 20],
            "SendUnsubscribedMessageEvent" => ["handleUnsubscribedMessage", 5],
            "SendErrorMessage"             => ["handleErrorMessage", 5]
        ];
    }
}