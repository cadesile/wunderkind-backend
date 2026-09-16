## EasyAdmin Custom Routes

Custom admin POST-action routes must **always** redirect through EasyAdmin's entry point:

```php
// CORRECT — initialises the `ea` context
return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_my_route']));

// WRONG — bypasses `ea` context; causes "i18n on null" Twig error
return $this->redirectToRoute('admin_my_route');
```

This rule generalizes beyond redirects: `AdminRouterSubscriber` only populates the `ea` Twig context when the **matched route** carries EasyAdmin's `routeCreatedByEasyAdmin` flag — true only for the dashboard's own `/admin` route, regardless of HTTP method. So **any** custom action that renders an `@EasyAdmin`-extending template directly (not just ones that redirect) must also be *reached* via `/admin?routeName=...`, not hit as a plain route — including POST actions. In Twig, target such a form at `path('admin', {routeName: 'admin_my_route'})` rather than `path('admin_my_route')`; `routeName` is read from the query string, so this works for POST bodies too.
