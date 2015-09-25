WebExcess.FlowKeyCDN
====================

A Package to use KeyCDN for persistent resources in the Flow Framework

Note: This package is still experimental and not for production.

Quick start
-----------

**Create a KeyCDN account**

[https://www.keycdn.com/](https://www.keycdn.com/)

**Rename the package `Settings.yaml.sample` file and fill in your KeyCDN credentials**

```yaml
WebExcess:
  FlowKeyCDN:
    default:
      host: ftp.keycdn.com
      user: usernamexy
      pass: 1234567890
      zone: sitexyresources
      zoneDomain: 'sitexyresources-1234.kxcdn.com'
      apiKey: 1234567890abcdefghjiklmnopqrstuvwxyz
      debug: false
```

**Enable the new persistent storage and target in your site `Settings.yaml`**

```yaml
TYPO3:
  Flow:
    resource:

      storages:
        keyCDNPersistentResourcesStorage:
          storage: 'WebExcess\FlowKeyCDN\KeyCDNStorage'

      collections:
        persistent:
          storage: 'keyCDNPersistentResourcesStorage'
          target: 'keyCDNWebDirectoryPersistentResourcesTarget'

      targets:
        keyCDNWebDirectoryPersistentResourcesTarget:
          target: 'WebExcess\FlowKeyCDN\KeyCDNTarget'
```

**Clean up and re-import your site** 

```
./flow flow:cache:flush --force ./flow site:prune; ./flow media:clearthumbnails; ./flow resource:clean; ./flow site:import --package-key TYPO3.NeosDemoTypo3Org; ./flow flow:cache:flush --force
```
