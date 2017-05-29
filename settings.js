/**
 * Delete a given account
 */
function micropubDeleteIdentity(accountUrl)
{
    new Ajax.Request('backend.php', {
        parameters: {
            'op':     'pluginhandler',
            'plugin': 'micropub',
            'method': 'action',
            'mode':   'deleteIdentity',
            'me':     accountUrl,
        },
        onSuccess: function(transport) {
            notify_info('Account removed');
            var elems = dojo.query('tr[data-url="' + accountUrl + '"]');
            if (elems.length > 0) {
                elems.first().remove();
            }
        },
        onFailure: function(transport) {
            notify_error(transport.responseText);
        }
    });
}
