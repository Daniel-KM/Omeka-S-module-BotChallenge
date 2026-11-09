Bot Challenge (module for Omeka S)
==================================

> __New versions of this module and support for Omeka S version 3.0 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

[Bot Challenge] is a module for [Omeka S] that protects public pages against
automated bots using a self-hosted JavaScript challenge. It does not depend on
any third piracy service (google, cloudflare, etc.), so it is GDPR compliant.

Visitors without a valid cookie are redirected to a verification page, where a
javascript challenge must be completed to get one. Bots, most of whom can't
execute javascript currently, remain trapped in a redirect loop and cannot crawl
the site. The module also detects headless browsers (Selenium, Puppeteer,
PhantomJS, etc.).

It is inspired by the mechanism used in [AtoM] (Access to Memory) and many
similar tools (wordfence [Wordpress], antibot [Drupal], etc.).


Installation
------------

See general end user documentation for [installing a module].

This module requires the module [Common], version 3.4.96 or later, that should
be installed first.

* Composer (recommended, requires Omeka [pull request #2432])

Install the module from the root of Omeka S:

```sh
composer require daniel-km/omeka-s-module-bot-challenge
```

The module is automatically downloaded in `composer-addons/modules/` and ready
to enable in the admin interface.

* From the zip

Download the last release [BotChallenge.zip] from the list of releases, and
uncompress it in the `modules` directory. Rename the name of the folder of the
module to `BotChallenge`

* From the source and for development

If the module was installed from the source, rename the name of the folder of
the module to `BotChallenge`.

* For test

The module includes a comprehensive test suite with unit and functional tests.
Run them from the root of Omeka:

```sh
vendor/bin/phpunit -c modules/BotChallenge/phpunit.xml --testdox
```


Quick start
-----------

Once enabled, the module is active with default settings: all public pages are
protected. Admin pages, api routes, cli jobs, static files, system routes
(migrate, login, etc.) pages are not protected.

The configuration can be adjusted in the module config form.

- HMAC salt: token used for test (leave empty to autogenerate one)
- Challenge delay: Time the visitor must wait (5 seconds)
- Cookie lifetime: How long the challenge cookie remains valid (7 days). The
  cookie is renewed once half of its lifetime is spent, so an active visitor is
  never challenged again; only an inactive one is.
- Bind the cookie to the ip: add the ip to the signature, so a cookie shared
  with another machine is refused. Disabled by default, and only relevant on a
  controlled network: see the limits below. Toggling it invalidates all
  cookies.
- Detect headless browsers: Run additional tests for headless environments
- Action of the visitor: None (automatic redirection), a checkbox to check or a
  button to click once the delay is over, to stop the bots that run the
  javascript without interacting with the page. A click triggered by a script
  is ignored. It is a simple obstacle, not a proof: the server cannot check that
  the click was real.
- Exception paths: /api, /api-local
- Exempted search engines: check the engines whose published ip ranges are
  excluded from the challenge
- Update the ip ranges now: run a job to refresh the bundled ranges
- Exception ips: list of IPv4/IPv6/cidr ranges, added to the ranges of the
  engines above: the networks of the institution, a partner harvester, etc.

The ip of the visitor is read in the headers X-Forwarded-For (the first ip from
the right that is not a trusted proxy) and X-Real-IP only when the request comes
from a trusted reverse proxy, else anybody could write an excepted ip in them.
The trusted proxies are set in the main settings (section Security). When the
list is empty, the proxies with a private or loopback address are trusted, so
the module works without config with or without a proxy in the same host or
network (Traefik, nginx, Docker). Set the list when the proxy has a public
address (load balancer, cdn) or when the local network is not trusted.

### How the challenge works

1. A visitor without a valid cookie is redirected to the challenge page, that
   holds a single use nonce, tied to the session. The page delivers no cookie:
   a plain request returns nothing usable.
2. The javascript checks the browser (headless detection), waits for the delay,
   asks for the action of the visitor if the option is set, then posts the nonce
   back.
3. The server checks the nonce, consumes it, and sets the cookie itself. So the
   cookie is `HttpOnly`: the client never needs to read it.

The cookie holds a timestamp and a signature (HMAC-SHA256) covering that
timestamp and the user agent, with the salt as key. Binding the user agent
prevents a cookie from being shared: a cookie obtained once and distributed to
other clients is refused.

The ip is not part of the signature by default. It can be added with the option
above, but it has two limits, so it is only relevant on a controlled network
(intranet, institution with a fixed egress), where the ips are stable and where
sharing a cookie between machines is precisely what should be forbidden.

**The ip changes although the visitor did not move.** The privacy extensions of
ipv6 rotate the address of a desktop, usually every day. A dual stack client
may exit in ipv4 or in ipv6 for the same site. A residential ip is renewed when
the router restarts. The icloud private relay, a vpn or tor change the egress,
sometimes between two connections. An university or a company may have several
egress ips in load balancing. A mobile network is only one case among others.

**The ip is shared by different visitors.** Behind a mobile cgnat or the nat of
a library, a school or an university — the very public of Omeka S — thousands of
people go out with the same ip, and a cookie shared inside that perimeter passes
the check anyway.

So the option refuses legitimate visitors that did nothing, and still accepts a
cookie shared between colocated machines.

The delay of the countdown is checked by the server too: the page runs it, so
without this check a client posting the answer immediately would get a cookie.

Note that this binding is not a proof of identity, since a user agent is
declarative: a client copying the header along with the cookie would pass. The
barrier is the nonce, that forces each client to hold a session and to make the
round trip.

### Exempted search engines

The module exempts by ip/cidr only: a user agent is declarative, so it cannot be
opposed to a scraper that presents itself as a browser. **Without exemption, the
search engines receive the javascript challenge, do not solve it, and stop
indexing the site.**

The ip ranges of eight engines are bundled with the module (Googlebot, Bingbot,
Applebot, DuckDuckBot, Qwant, MojeekBot, Marginalia, Yandex) and selectable with
checkboxes. All but Yandex are exempted by default. Sources are first-hand when
the operator publishes one (Google, Bing and Apple publish a json), else the
[GoodBots] aggregate. Qwant publishes no list, so the prefix announced by its
autonomous system is used (AS199064).

Some indexers are not bundled deliberately: Common Crawl (training corpus, not a
search engine), the commercial seo crawlers (Ahrefs, Semrush) and the ones that
use a cdn, since it would allow anyone routing requests through it.

The lists move, so a job refreshes them from their sources via a job that can
be run from the config form.


TODO
----

- [ ] Rate limiting for api (see [Mediawiki])
- [ ] Anti robots BlackHole (see [Wordpress blackhole])
- [ ] Remove ux challenge
- [ ] Reverse DNS inverse to avoid CDN issue.


Warning
-------

Use it at your own risk.

It's always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.

Changing the salt invalidates all existing cookies: every visitor will need to
complete the challenge again.

The cookie is bound to the user agent, so a visitor updating its browser
completes the challenge again.


Troubleshooting
---------------

See online issues on the [module issues] page on GitLab.


License
-------

This module is published under the [CeCILL v2.1] license, compatible with
[GNU/GPL] and approved by [FSF] and [OSI].

This software is governed by the CeCILL license under French law and abiding by
the rules of distribution of free software. You can use, modify and/ or
redistribute the software under the terms of the CeCILL license as circulated by
CEA, CNRS and INRIA at the following URL "http://www.cecill.info".

As a counterpart to the access to the source code and rights to copy, modify and
redistribute granted by the license, users are provided only with a limited
warranty and the software's author, the holder of the economic rights, and the
successive licensors have only limited liability.

In this respect, the user's attention is drawn to the risks associated with
loading, using, modifying and/or developing or reproducing the software by the
user in light of its specific status of free software, that may mean that it is
complicated to manipulate, and that also therefore means that it is reserved for
developers and experienced professionals having in-depth computer knowledge.
Users are therefore encouraged to load and test the software's suitability as
regards their requirements in conditions enabling the security of their systems
and/or data to be ensured and, more generally, to use and operate it in the same
conditions as regards security.

The fact that you are presently reading this means that you have had knowledge
of the CeCILL license and that you accept its terms.


Copyright
---------

- Copyright Daniel Berthereau, 2026 (see [Daniel-KM] on GitLab)

The idea of this modules comes from a [thread in omeka forum].


[Bot Challenge]: https://gitlab.com/Daniel-KM/Omeka-S-module-BotChallenge
[Omeka S]: https://omeka.org/s
[AtoM]: https://www.accesstomemory.org/en/docs/2.10/admin-manual/security/js-challenge
[Wordpress]: https://wordpress.org/plugins/wordfence
[Drupal]: https://www.drupal.org/project/antibot
[GoodBots]: https://github.com/AnTheMaker/GoodBots
[Mediawiki]: https://www.mediawiki.org/wiki/API:Ratelimit
[Wordpress blackhole]: https://wordpress.org/plugins/blackhole-bad-bots
[pull request #2432]: https://github.com/omeka/omeka-s/pull/2432
[BotChallenge.zip]: https://gitlab.com/Daniel-KM/Omeka-S-module-BotChallenge/-/releases
[thread in omeka forum]: https://forum.omeka.org/t/inquiry-about-bot-traffic-control-in-omeka-classic/28800
[installing a module]: https://omeka.org/s/docs/user-manual/modules/#installing-modules
[Common]: https://gitlab.com/Daniel-KM/Omeka-S-module-Common
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-BotChallenge/-/work_items
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
