/**
 * Sharing plugin front end: the four gate booleans the server sets, the
 * listing page tool and the composer dialog.
 * @module Sharing
 */
"use strict";
(function (Q, $) {

var Sharing = Q.Sharing = Q.plugins.Sharing = Q.plugins.Sharing || {};

// Q ships String.prototype.encodeHTML rather than an escaping namespace.
function esc(s) {
	return String(s == null ? '' : s).encodeHTML();
}

['canCreateOffer', 'canRespondToOffer', 'canCreateNeed', 'canRespondToNeed']
.forEach(function (gate) { Sharing[gate] = !!Sharing[gate]; });

Q.Text.addFor(['Q.Tool.define', 'Q.Template.set'], 'Sharing/', ['Sharing/content']);

Sharing.Listing = {
	/**
	 * Url of a listing's page.
	 * @method url
	 */
	url: function (listing) {
		return listing.url || Q.url('sharing/' + listing.publisherId + '/' + listing.id);
	},

	/**
	 * Create a listing through the same POST the composer submits.
	 * @method create
	 * @param {Object} fields direction, kind, title, content, area, exclusive, custody, private
	 * @param {Function} callback (err, listing)
	 */
	create: function (fields, callback) {
		Q.req('Sharing/listing', ['stream'], function (err, data) {
			var msg = Q.firstErrorMessage(err, data);
			if (msg) {
				return Q.handle(callback, Sharing, [msg]);
			}
			Q.handle(callback, Sharing, [null, data.slots.stream]);
		}, {method: 'post', fields: fields});
	},

	/**
	 * Open the composer dialog.
	 * @method compose
	 * @param {Object} [options]
	 * @param {String} [options.direction='offer'] initial direction
	 * @param {Function} [options.onCreated] called with the new listing
	 */
	compose: function (options) {
		options = options || {};
		Q.Text.get('Sharing/content', function (err, text) {
			var t = text.composer;
			var canOffer = Sharing.canCreateOffer, canNeed = Sharing.canCreateNeed;
			var direction = options.direction || (canOffer ? 'offer' : 'need');
			var $form = $(
				'<form class="Sharing_listing_composer" autocomplete="off">' +
				'<fieldset class="Sharing_composer_direction"><legend>' + esc(t.Direction) + '</legend>' +
				(canOffer ? radio('direction', 'offer', t.direction.offer, direction === 'offer') : '') +
				(canNeed ? radio('direction', 'need', t.direction.need, direction === 'need') : '') +
				'</fieldset>' +
				'<label class="Sharing_composer_kind">' + esc(t.Kind) +
				'<select name="kind">' +
				'<option value="item">' + esc(t.kind.item) + '</option>' +
				'<option value="service">' + esc(t.kind.service) + '</option>' +
				'</select></label>' +
				'<label>' + esc(t.TitleLabel) +
				'<input type="text" name="title" maxlength="255" placeholder="' + esc(t.TitlePlaceholder) + '"></label>' +
				'<label>' + esc(t.Content) +
				'<textarea name="content" rows="4" placeholder="' + esc(t.ContentPlaceholder) + '"></textarea></label>' +
				'<label>' + esc(t.Area) +
				'<input type="text" name="area" maxlength="255" placeholder="' + esc(t.AreaPlaceholder) + '"></label>' +
				'<div class="Sharing_composer_facts">' +
				'<label><input type="checkbox" name="exclusive" value="1" checked> ' + esc(t.Exclusive) + '</label>' +
				'<label><input type="checkbox" name="custody" value="1" checked> ' + esc(t.Custody) + '</label>' +
				'</div>' +
				'<label class="Sharing_composer_private">' + esc(t.Private) +
				'<small>' + esc(t.PrivateHint) + '</small>' +
				'<textarea name="private" rows="3"></textarea></label>' +
				'<div class="Sharing_composer_error" hidden></div>' +
				'<button type="submit" class="Q_button Sharing_composer_submit">' + esc(t.Submit) + '</button>' +
				'</form>'
			);
			// Defaults follow the kind; the server applies the same rule
			// when a field is absent, so these are for the user's eyes.
			$form.find('select[name=kind]').on('change', function () {
				var isItem = $(this).val() === 'item';
				$form.find('input[name=exclusive]').prop('checked', isItem);
				$form.find('input[name=custody]').prop('checked', isItem);
				$form.find('.Sharing_composer_facts').toggle(isItem);
			});
			$form.on('submit', function (e) {
				e.preventDefault();
				var $error = $form.find('.Sharing_composer_error');
				var $submit = $form.find('.Sharing_composer_submit');
				var fields = {
					direction: $form.find('input[name=direction]:checked').val() || direction,
					kind: $form.find('select[name=kind]').val(),
					title: $.trim($form.find('input[name=title]').val()),
					content: $form.find('textarea[name=content]').val(),
					area: $form.find('input[name=area]').val(),
					exclusive: $form.find('input[name=exclusive]').is(':checked') ? 1 : 0,
					custody: $form.find('input[name=custody]').is(':checked') ? 1 : 0,
					'private': $form.find('textarea[name=private]').val()
				};
				if (!fields.title) {
					$error.text(t.TitleRequired).prop('hidden', false);
					$form.find('input[name=title]').focus();
					return;
				}
				$error.prop('hidden', true);
				$submit.prop('disabled', true).text(t.Posting);
				Sharing.Listing.create(fields, function (err, listing) {
					if (err) {
						$submit.prop('disabled', false).text(t.Submit);
						$error.text(err).prop('hidden', false);
						return;
					}
					Q.Dialogs.pop();
					Q.handle(options.onCreated, Sharing, [listing]);
				});
			});
			Q.Dialogs.push({
				title: t.Title,
				content: $form[0],
				className: 'Sharing_listing_composer_dialog',
				onActivate: function () {
					$form.find('input[name=title]').focus();
				}
			});
			function radio(name, value, label, checked) {
				return '<label><input type="radio" name="' + name + '" value="' + value + '"' +
					(checked ? ' checked' : '') + '> ' + esc(label) + '</label>';
			}
		});
	}
};

Sharing.Engagement = {
	/**
	 * Respond to a listing through the same POST the form submits.
	 * @method propose
	 * @param {Object} fields publisherId, listingId, note, quantity
	 */
	propose: function (fields, callback) {
		Q.req('Sharing/engagement', ['engagement'], function (err, data) {
			var msg = Q.firstErrorMessage(err, data);
			if (msg) {
				return Q.handle(callback, Sharing, [msg]);
			}
			Q.handle(callback, Sharing, [null, data.slots.engagement]);
		}, {method: 'post', fields: fields});
	},
	/**
	 * Apply a lifecycle verb.
	 * @method transition
	 * @param {Object} fields publisherId, engagementId, transition
	 */
	transition: function (fields, callback) {
		Q.req('Sharing/engagement', ['engagement'], function (err, data) {
			var msg = Q.firstErrorMessage(err, data);
			if (msg) {
				return Q.handle(callback, Sharing, [msg]);
			}
			Q.handle(callback, Sharing, [null, data.slots.engagement]);
		}, {method: 'put', fields: fields});
	}
};

/**
 * Wire every transition button inside a container: the button's row (or
 * the page's own engagement element) carries the ids. Reloads the page on
 * success so the server re-renders the new state and buttons.
 */
function bindTransitions() {
	$('.Sharing_transition_button').on(Q.Pointer.fastclick, true, function () {
		var $button = $(this);
		var $holder = $button.closest('[data-engagementId]');
		var $error = $button.closest('.Sharing_engagement, .Sharing_listing_engagements, .Sharing_listing_mine')
			.find('.Sharing_transition_error').first();
		$error.prop('hidden', true);
		$button.prop('disabled', true);
		Sharing.Engagement.transition({
			publisherId: $holder.attr('data-publisherId'),
			engagementId: $holder.attr('data-engagementId'),
			transition: $button.attr('data-transition')
		}, function (err) {
			if (err) {
				$button.prop('disabled', false);
				$error.text(err).prop('hidden', false);
				return;
			}
			Q.handle(window.location.href);
		});
		return false;
	});
}

Q.page('Sharing/listing', function () {
	bindTransitions();
	$('form.Sharing_respond_form').on('submit', true, function (e) {
		e.preventDefault();
		var $form = $(this);
		var $holder = $form.closest('[data-streamName]');
		var $error = $form.find('.Sharing_respond_error');
		var $submit = $form.find('.Sharing_respond_button');
		$error.prop('hidden', true);
		$submit.prop('disabled', true);
		Sharing.Engagement.propose({
			publisherId: $holder.attr('data-publisherId'),
			listingId: $holder.attr('data-streamName').replace(/^Sharing\/listing\//, ''),
			note: $form.find('textarea[name=note]').val(),
			quantity: $form.find('input[name=quantity]').val() || 1
		}, function (err, engagement) {
			if (err) {
				$submit.prop('disabled', false);
				$error.text(err).prop('hidden', false);
				return;
			}
			Q.handle(engagement.url);
		});
		return false;
	});
}, 'Sharing');

Q.page('Sharing/engagement', function () {
	bindTransitions();
}, 'Sharing');

/**
 * The listing page is server-rendered; this wires the post button to the
 * composer and navigates to the new listing. Bound with the `true` scope
 * marker so Q.loadUrl unbinds it before the next page, rather than stacking
 * one handler per visit (docs/qbix-gotchas.md, "Binding inside Q.page()").
 */
Q.page('Sharing/listings', function () {
	$('.Sharing_post_button').on(Q.Pointer.fastclick, true, function () {
		Sharing.Listing.compose({
			onCreated: function (listing) {
				Q.handle(Sharing.Listing.url(listing));
			}
		});
		return false;
	});
}, 'Sharing');

})(Q, Q.jQuery);
