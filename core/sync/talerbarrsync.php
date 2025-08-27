<?php
//FIXME: Implement a program that can be called from outside, and will run in the background that will work on sycnronisizing the
//dolibarr and taler so far only inventories, and only in the specified direction from the config
// we somehow want to show the information about the progress we can do it with some file, that will be read only by this script
// or whatever else might also work
// this file, needs to have info about the current run e.g.
// processed from dolibarr/all from dolibarr or processed from taler/all from taler depending on sync dirrection
// maybe the error handler or something else
// so that we can't request a second time run, if the already one is running, but it would be nice,
// if the sync from dolibarr we need to get all products from dolibarr and translate them to taler
