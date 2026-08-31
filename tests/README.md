# Tests

Losse controle van de beslislogica (Matcher, Environment, Http_Guard, Mail_Guard,
Cron_Guard) zonder dat er een WordPress-installatie nodig is. `bootstrap.php`
bevat minimale stubs van de WordPress-functies die deze klassen gebruiken.

Draaien:

```bash
php tests/test-logic.php
```

Dit dekt de logica, niet de beheerschermen. Die test je op een echte
WordPress-installatie — zie INSTALL.md.
