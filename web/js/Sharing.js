/**
 * Sharing plugin front-end namespace. Slice 1 only establishes
 * Q.plugins.Sharing and the four gate booleans the server sets; the
 * Sharing/* tools arrive with the slices that need them.
 *
 * @module Sharing
 */
"use strict";
(function (Q) {

var Sharing = Q.Sharing = Q.plugins.Sharing = Q.plugins.Sharing || {};

['canCreateOffer', 'canRespondToOffer', 'canCreateNeed', 'canRespondToNeed']
.forEach(function (gate) { Sharing[gate] = !!Sharing[gate]; });

})(Q);
