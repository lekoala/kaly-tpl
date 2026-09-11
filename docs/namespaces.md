# Namespaced paths

Additional view roots can be registered explicitly:

```php
$view->addPath('admin', __DIR__ . '/modules/admin/views');
```

Both namespace syntaxes are supported:

```php
echo $view->render('@admin/users/index');
echo $view->render('admin::users/index');
```

Template existence can be checked without rendering:

```php
if ($view->exists('@admin/users/index')) {
    // ...
}
```

Resolved templates are constrained to their registered root, including when symlinks are involved.
