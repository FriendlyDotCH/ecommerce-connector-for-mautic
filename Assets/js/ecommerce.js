Mautic.ecommerceBindTimelineOrderLinks = function (container) {
    var scope = container || 'body';

    mQuery(scope).find('.js-ecommerce-order-timeline').each(function () {
        var $marker = mQuery(this);
        var href = $marker.attr('data-order-url');
        var header = $marker.attr('data-order-header');
        var title = $marker.attr('data-order-title');
        var $detailsRow = $marker.closest('tr.timeline-details');
        var $mainRow = $detailsRow.prevAll('tr.timeline-row').not('.timeline-date-header').first();

        if (!$mainRow.length || !href) {
            $marker.remove();
            return;
        }

        $mainRow.find('.table-expand-button').addClass('hide');
        $mainRow.css('cursor', 'pointer');
        if (title) {
            $mainRow.attr('title', title);
        }

        $mainRow.off('click.ecommerceOrder').on('click.ecommerceOrder', function (event) {
            if (mQuery(event.target).closest('.table-expand').length) {
                return;
            }

            event.preventDefault();

            var $dialog = mQuery('#MauticSharedModal .modal-dialog');
            $dialog.addClass('modal-lg');
            mQuery('#MauticSharedModal').one('hidden.bs.modal.ecommerceOrderSize', function () {
                $dialog.removeClass('modal-lg');
            });

            var el = mQuery('<a/>', {
                href: href,
                'data-toggle': 'ajaxmodal',
                'data-target': '#MauticSharedModal',
                'data-header': header
            }).get(0);

            Mautic.ajaxifyModal(el, event);
        });

        $marker.remove();
    });
};

mQuery(document).on('mautic:onPageLoad:before', function (event, container) {
    Mautic.ecommerceBindTimelineOrderLinks(container);
});
