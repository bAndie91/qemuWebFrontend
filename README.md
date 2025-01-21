## Features

- manage several VM in a plain but well defined directory structure
- sophisticated auto-completion for configuration options and file paths
- has "kvm-console" ie. web-keyboard and screenshot which updates on your typing bursts and also tries to dispatch mouse clicks (it's difficult with relative virtual mouse devices, but works well with "tablet" v-mouse)
- integrated noVNC, so you can feel more realtime kvm-console experience (it requires nginx websocket module, but does not open tcp ports because communicates on unix domain sockets)
- save/restore VM, so you can hibernate your vm to the disk and restore as well
- no fancy css design yet, but templating is in place :)

## Screenshots

![vm-list](screenshot/vm-list.png)
![console](screenshot/console.png)
![edit-config-autocomplete-file](screenshot/edit-config-autocomplete-file.png)
![edit-config-autocomplete-vnc](screenshot/edit-config-autocomplete-vnc.png)
![config-vnc](screenshot/config-vnc.png)
![inspect-devices](screenshot/inspect-devices.png)

# Project issues, bugs, feature requests, ideas

1. clone the repo
2. use [git-bug](https://github.com/git-bug/git-bug) to open a new ticket in this repo
3. find one or more person in the commit history to make contact with, then either
4.a. send your newly created `git-bug` ticket (or patch if you already propose a code change) via email, or
4.b. send the URL of your git clone to a contributor (via email or other channel), and request them to pull (`git-bug` issues and/or branches as well) from you.
