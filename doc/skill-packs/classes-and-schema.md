# Skill pack: Classes and schema

The content model: class inventory, unused and largest classes, groups, attributes with required, search, translation and form flags, datatypes, name and URL patterns, and class comparisons for migrations.

- Pack: `classes-and-schema` 0.0.0.2 in `share/skills/classes-and-schema/` (Exponential Tron's own packs)
- Prefix: `class.`
- Skills: 12
- Audience: Developers and site builders who change the content model or migrate between classes.

## Skills

| Skill | What it answers | Parameters | Units | Wave |
|---|---|---|---|---|
| `class.inventory` | Every content class with its group, whether it is a container and how many objects it has. | (none) | class_list | A |
| `class.unused` | The content classes that have no objects at all: candidates for removal. | (none) | class_list | A |
| `class.largest` | The content classes with the most objects, largest first. | (none) | class_list | A |
| `class.containers` | The content classes that can hold children, or with containers false those that cannot, with their object counts. | `--containers` | class_list | A |
| `class.groups` | How many content classes each class group holds. | (none) | class_list | A |
| `class.group` | The content classes of one class group, with their object counts. | `--group` | class_list | A |
| `class.attributes` | The attributes of a content class in order: identifier, name, datatype and whether required, searchable and translatable. | `--identifier` | class_describe | A |
| `class.attribute-flag` | The attributes of a content class with a flag set or not set: information collectors (forms), searchable, translatable or required. | `--flag` `--set` `--identifier` | class_describe | A |
| `class.datatypes` | How many attributes of each datatype a content class has. | `--identifier` | class_describe | A |
| `class.patterns` | How a content class names its objects and builds their URL aliases, and its default sort. | `--identifier` | class_describe | A |
| `class.compare.pair` | The differences between two content classes (e.g. a classic class and its media-site counterpart): attributes only in one of them and attributes that differ, counted per kind of change. | `--identifier` `--other` | class_diff | A |
| `class.compare` | The differences between any two content classes of this installation. | `--identifier` `--other` | class_diff | A |

## Examples

### Class inventory

Every content class with its group, whether it is a container and how many objects it has.

```
xsh tron:skill:run class.inventory
```

### Unused classes

The content classes that have no objects at all: candidates for removal.

```
xsh tron:skill:run class.unused
```

### Largest classes

The content classes with the most objects, largest first.

```
xsh tron:skill:run class.largest
```

### Container classes

The content classes that can hold children.

```
xsh tron:skill:run class.containers --containers true
```

### Leaf classes

The content classes that cannot hold children.

```
xsh tron:skill:run class.containers --containers false
```

Back to [skills.md](../skills.md) and the [conventions](../extension-conventions.md).

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
