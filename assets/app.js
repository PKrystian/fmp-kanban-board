import 'bootstrap/dist/css/bootstrap.min.css';
import { Offcanvas } from 'bootstrap';
import $ from 'jquery';

$(function () {
    const $board = $('[data-kanban-board]');
    const panelElement = document.querySelector('[data-card-panel]');

    if (!$board.length || !panelElement) {
        return;
    }

    const $panel = $(panelElement);
    const panel = Offcanvas.getOrCreateInstance(panelElement);
    let panelUrl = null;

    const templateContent = (selector) => {
        const template = document.querySelector(selector);

        return template.content.cloneNode(true);
    };

    const refreshColumn = ($column) => {
        const $list = $column.find('[data-card-list]').first();
        const count = $list.children('[data-card-entry]').length;

        $column.find('[data-card-count]').first().text(count);

        if (count === 0 && !$list.children('[data-empty-column]').length) {
            $list.append(templateContent('[data-empty-column-template]'));
        }

        if (count > 0) {
            $list.children('[data-empty-column]').remove();
        }
    };

    const showFeedback = ($response) => {
        const $feedback = $response.find('[data-feedback-fragment]').children().first().detach();

        $board.find('[data-board-feedback]').first().empty().append($feedback);
    };

    const applyCardMutation = (html) => {
        const $response = $(html);
        const $entry = $response.find('[data-card-fragment] > [data-card-entry]').first().detach();
        const cardId = $entry.attr('data-card-id');
        const columnId = $entry.attr('data-card-column-id');
        const $existing = $board.find(`[data-card-entry][data-card-id="${cardId}"]`).first();
        const $sourceColumn = $existing.closest('[data-board-column]');
        const $targetColumn = $board.find(`[data-board-column][data-column-id="${columnId}"]`).first();

        if ($existing.length) {
            if ($sourceColumn.is($targetColumn)) {
                $existing.replaceWith($entry);
            } else {
                $existing.remove();
                $targetColumn.find('[data-card-list]').first().append($entry);
            }
        } else {
            $targetColumn.find('[data-card-list]').first().append($entry);
        }

        refreshColumn($targetColumn);
        if ($sourceColumn.length && !$sourceColumn.is($targetColumn)) {
            refreshColumn($sourceColumn);
        }

        showFeedback($response);

        return $response;
    };

    const loadPanel = (url) => {
        panelUrl = url;
        $panel.empty().append(templateContent('[data-card-panel-loading-template]'));
        panel.show();

        $.ajax({ url, method: 'GET' })
            .done((html) => {
                $panel.html(html);
            })
            .fail(() => {
                $panel.empty().append(templateContent('[data-card-panel-error-template]'));
            });
    };

    $board.on('click', '[data-card-panel-url]', function (event) {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
            return;
        }

        event.preventDefault();
        loadPanel(this.href);
    });

    $panel.on('click', '[data-card-panel-retry]', function () {
        loadPanel(panelUrl);
    });

    $panel.on('submit', '[data-card-panel-form]', function (event) {
        event.preventDefault();

        const $form = $(this);
        const $submit = $form.find('[type="submit"]');
        $submit.prop('disabled', true);

        $.ajax({
            url: $form.attr('action'),
            method: $form.attr('method') || 'POST',
            data: $form.serialize(),
        })
            .done((html) => {
                applyCardMutation(html);
                panel.hide();
            })
            .fail((request) => {
                if (request.status === 422) {
                    $panel.html(request.responseText);
                    return;
                }

                $panel.empty().append(templateContent('[data-card-panel-error-template]'));
            })
            .always(() => {
                $submit.prop('disabled', false);
            });
    });

    $board.on('submit', '[data-quick-create-form]', function (event) {
        event.preventDefault();

        const $form = $(this);
        const $container = $form.closest('[data-quick-create]');
        const $submit = $form.find('[type="submit"]');
        $container.find('[data-quick-create-error]').remove();
        $submit.prop('disabled', true);

        $.ajax({
            url: $form.attr('action'),
            method: $form.attr('method') || 'POST',
            data: $form.serialize(),
        })
            .done((html) => {
                const $response = applyCardMutation(html);
                const $replacement = $response.find('[data-quick-create-fragment]').children().first().detach();
                $container.replaceWith($replacement);
            })
            .fail((request) => {
                if (request.status === 422) {
                    $container.replaceWith(request.responseText);
                    return;
                }

                $container.prepend(templateContent('[data-quick-create-error-template]'));
            })
            .always(() => {
                $submit.prop('disabled', false);
            });
    });

    $board.on('click', '[data-quick-create-retry]', function () {
        $(this).closest('[data-quick-create]').find('[data-quick-create-form]').trigger('submit');
    });
});
