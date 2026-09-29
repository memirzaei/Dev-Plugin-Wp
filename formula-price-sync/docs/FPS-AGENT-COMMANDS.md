# Formula Price Sync — Agent Command Center

Canonical local commands for Codex/Cline/Kilo-style agents.

Run from the repository root:

~~~powershell
.\formula-price-sync\bin\fps-agent.ps1 status
.\formula-price-sync\bin\fps-agent.ps1 test
.\formula-price-sync\bin\fps-agent.ps1 smoke
.\formula-price-sync\bin\fps-agent.ps1 wamp
.\formula-price-sync\bin\fps-agent.ps1 gate
.\formula-price-sync\bin\fps-agent.ps1 release-check
~~~

## Commands

| Command | Purpose |
|---|---|
| status | Repository, branch, toolchain and working-tree status |
| test | Core smoke + PHPUnit |
| smoke | Smoke/R07-R08/R09-R13/HPOS suite |
| wamp | Existing real WAMP gate with real WP/WooCommerce/HPOS integration |
| gate | Composer + smoke + PHPUnit + PHPCS + metadata + real WAMP |
| release-check | Metadata + release build + bypass/skip scan; does not publish |

Optional:

~~~powershell
.\formula-price-sync\bin\fps-agent.ps1 gate -WpPath C:\wamp64\www\fps-cert
.\formula-price-sync\bin\fps-agent.ps1 gate -ContinueOnError
.\formula-price-sync\bin\fps-agent.ps1 gate -Json
~~~

## Agent rules

- These commands use the repository's existing test/gate runners.
- A failed command returns a non-zero exit code.
- No engineering/test bypass flags are enabled.
- wamp and gate use the real certification WordPress path by default.
- Do not use -AutoCommit through the WAMP runner from this command center.
- Do not treat missing/unrun gates as PASS.
- Do not add product/license tokens to the repository or release ZIP.
