<?php
/**
 * Sharing plugin static facade.
 *
 * Members offer resources (an item, their time, a space) and other members
 * book them. This class holds the label gates and the community-scoped
 * helpers every handler needs; the resource and booking logic live in
 * Sharing_Resource and Sharing_Booking.
 *
 * App-agnostic on purpose: the labels come from config and the community
 * from Users::currentCommunityId(), never from a host app's name.
 *
 * @module Sharing
 */
/**
 * @class Sharing
 */
abstract class Sharing
{
	/**
	 * Whether a user may offer a resource in the current community.
	 * @method canOffer
	 * @static
	 * @param {string|null} [$userId=null] Defaults to the logged-in user.
	 * @return {boolean}
	 */
	static function canOffer($userId = null)
	{
		return self::hasAnyLabel('canOffer', $userId);
	}

	/**
	 * Whether a user may request a resource in the current community.
	 * @method canRequest
	 * @static
	 * @param {string|null} [$userId=null] Defaults to the logged-in user.
	 * @return {boolean}
	 */
	static function canRequest($userId = null)
	{
		return self::hasAnyLabel('canRequest', $userId);
	}

	/**
	 * Throws unless the user may offer.
	 * @method requireCanOffer
	 * @static
	 * @throws {Users_Exception_NotAuthorized}
	 */
	static function requireCanOffer($userId = null)
	{
		if (!self::canOffer($userId)) {
			throw new Users_Exception_NotAuthorized();
		}
	}

	/**
	 * Throws unless the user may request.
	 * @method requireCanRequest
	 * @static
	 * @throws {Users_Exception_NotAuthorized}
	 */
	static function requireCanRequest($userId = null)
	{
		if (!self::canRequest($userId)) {
			throw new Users_Exception_NotAuthorized();
		}
	}

	/**
	 * The community whose labels gate sharing: the current one.
	 * @method communityId
	 * @static
	 * @return {string}
	 */
	static function communityId()
	{
		return Users::currentCommunityId(true);
	}

	/**
	 * The configured labels for a gate, with {{app}} interpolated.
	 * @method labels
	 * @static
	 * @param {string} $gate "canOffer" or "canRequest"
	 * @return {array}
	 */
	static function labels($gate)
	{
		$labels = (array)Q_Config::get('Sharing', 'labels', $gate, array());
		return array_map(function ($label) {
			return Q::interpolate($label, array('app' => Q::app()));
		}, $labels);
	}

	/**
	 * The idiom for "does this user hold any of these labels here", cached
	 * per request. Swap for Users_Label::hasLabel() when Qbix/Users#6 lands.
	 * @method hasAnyLabel
	 * @static
	 * @protected
	 */
	protected static function hasAnyLabel($gate, $userId = null)
	{
		if (!isset($userId)) {
			$user = Users::loggedInUser(false, false);
			if (!$user) {
				return false;
			}
			$userId = $user->id;
		}
		if (isset(Q::$state['Sharing'][$gate][$userId])) {
			return Q::$state['Sharing'][$gate][$userId];
		}
		$labels = self::labels($gate);
		$result = !empty($labels)
			&& !empty(Users::roles(self::communityId(), $labels, array(), $userId));
		Q::$state['Sharing'][$gate][$userId] = $result;
		return $result;
	}
}
