* Thruway Subscription Meta Module

This is a module for the [Thruway](https://github.com/voryx/Thruway) router
that provides [subscription meta events](https://github.com/wamp-proto/wamp-proto/blob/master/rfc/text/advanced/ap_pubsub_subscription_meta_api.md).

This module only implements the events portion of the spec.

This module does not group subscriptions. Every subscription will cause a `on_create` and `on_subscribe` event.

* Use

The module can be added to the router to provide
meta events for all realms:

```php
$router = new Router();
$router->registerModules([
    new RatchetTransportProvider(),
    new SubscriptionMetaModule()
]);

$router->start();
```
or as a realm module to provide meta events on 
individual realms:
```php
$router = new Router();
$router->registerModules([
    new RatchetTransportProvider()
]);

$realm = $router->getRealmManager()->getRealm('some_realm');
$realm->addModule(new SubscriptionMetaModule());

$router->start();
```
