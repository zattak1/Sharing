/*jshint node:true */
/**
 * Sharing plugin, node side. Q's Bootstrap.loadPlugins() requires
 * classes/<Plugin>.js for every plugin in Q.plugins and warns when it is
 * missing, so this exists even though the plugin has no node handlers yet.
 * No Base/Sharing mixin: the plugin owns no tables.
 * @module Sharing
 */
var Q = require('Q');

/**
 * @class Sharing
 * @static
 */
function Sharing() { }
module.exports = Sharing;
