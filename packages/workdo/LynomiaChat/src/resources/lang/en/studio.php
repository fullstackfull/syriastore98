<?php

/**
 * The Automation Studio words that must NOT come from the host's global dictionary.
 *
 * WHY THESE FOUR ARE HERE AND THE OTHER ~390 ARE NOT. This module's strings are JSON translations
 * keyed by their English text, which is the Workdo convention and works well -- except that the
 * host application has its own resources/lang/<locale>.json of ~2,800 keys in the SAME flat
 * namespace, and the host's wins. Four of the Studio's words collide with an entry there whose
 * Arabic says something else:
 *
 *   Live  -> "حياً"   "alive", the adjective for a living thing, not a running automation
 *   Off   -> "أطفئ"   an imperative, "turn it off!", on a badge that is reporting a state
 *   Yes   -> "نعم .." and No -> "لا .."   with trailing dots from some other screen's sentence
 *
 * Found in a browser, reading the Arabic page, not by inspecting the files. A namespaced PHP
 * group cannot be overridden by the host's flat JSON, so these four say what this product means
 * by them wherever they appear. The host's entries are left exactly as they are: other screens
 * use them and this module has no business editing another team's copy.
 */
return [
    'state_published' => 'Live',
    'state_disabled' => 'Off',
    'yes' => 'Yes',
    'no' => 'No',
];
