function getConditionPlaceholder(fieldValue) {
    const placeholders = {
        name: 'invoice',
        extension: 'pdf',
        size: '100MB',
        age: '30 Days',
    };

    return placeholders[fieldValue] ?? 'invoice';
}

function conditionNodes(container) {
    return Array.from(container.children).filter((child) => child.matches('[data-condition-node]'));
}

function bindLiveModel(element, property, debounce = false) {
    const modelAttribute = debounce ? 'wire:model.live.debounce.500ms' : 'wire:model.live';

    if (element.getAttribute(modelAttribute) === property) {
        return;
    }

    Array.from(element.attributes)
        .filter((attribute) => attribute.name.startsWith('wire:model'))
        .forEach((attribute) => element.removeAttribute(attribute.name));
    element.setAttribute(modelAttribute, property);
}

function updateConditionIndexes(container = document.querySelector('#conditions'), prefix = 'conditions') {
    if (!container) {
        return;
    }

    const nodes = conditionNodes(container);

    nodes.forEach((node, index) => {
        const base = `${prefix}[${index}]`;
        const removeButton = node.querySelector('[data-remove-condition]');

        if (removeButton) {
            removeButton.disabled = nodes.length === 1;
        }

        if (node.matches('.condition-group')) {
            const typeInput = node.querySelector('[data-group-type]');
            const groupOperator = node.querySelector('[data-group-operator]');
            const children = node.querySelector('[data-condition-container]');

            typeInput.name = `${base}[type]`;
            bindLiveModel(typeInput, `${base.replaceAll('[', '.').replaceAll(']', '')}.type`);
            groupOperator.name = `${base}[operator]`;
            bindLiveModel(groupOperator, `${base.replaceAll('[', '.').replaceAll(']', '')}.operator`);
            updateConditionIndexes(children, `${base}[conditions]`);

            return;
        }

        const modelBase = base.replaceAll('[', '.').replaceAll(']', '');
        const field = node.querySelector('[data-condition-field]');
        const operator = node.querySelector('[data-condition-operator]');
        const value = node.querySelector('[data-condition-value]');
        field.name = `${base}[field]`;
        bindLiveModel(field, `${modelBase}.field`);
        operator.name = `${base}[operator]`;
        bindLiveModel(operator, `${modelBase}.operator`);
        value.name = `${base}[value]`;
        bindLiveModel(value, `${modelBase}.value`, true);

        updateConditionRow(node);
    });
}

function updateConditionRow(row, resetOperator = false) {
    const field = row.querySelector('[data-condition-field]');
    const operator = row.querySelector('[data-condition-operator]');
    const value = row.querySelector('[data-condition-value]');
    const numericField = ['size', 'age'].includes(field.value);
    const operators = numericField
        ? [['equals', 'equals'], ['greater_than', 'is greater than'], ['less_than', 'is less than']]
        : [['contains', 'contains'], ['equals', 'equals'], ['starts_with', 'starts with'], ['ends_with', 'ends with']];
    const currentOperator = resetOperator ? '' : operator.value;
    const optionsMatch = operator.options.length === operators.length
        && operators.every(([optionValue], index) => operator.options[index]?.value === optionValue);

    if (!optionsMatch) {
        operator.replaceChildren(...operators.map(([optionValue, label]) => {
            const option = document.createElement('option');
            option.value = optionValue;
            option.textContent = label;

            return option;
        }));
    }

    operator.value = operators.some(([optionValue]) => optionValue === currentOperator)
        ? currentOperator
        : operators[0][0];
    value.placeholder = getConditionPlaceholder(field.value);
    value.type = field.value === 'age' ? 'number' : 'text';

    if (field.value === 'size') {
        value.inputMode = 'decimal';
    } else {
        value.removeAttribute('inputmode');
    }

    return operator.value !== currentOperator;
}

function appendCondition(container) {
    if (conditionNodes(container).length >= 12) {
        return;
    }

    const row = document.querySelector('#condition-row-template').content.firstElementChild.cloneNode(true);
    container.append(row);
    updateConditionIndexes();
}

function appendGroup(container) {
    if (conditionNodes(container).length >= 12) {
        return;
    }

    let depth = 0;
    let parent = container.closest('.condition-group');

    while (parent) {
        depth++;
        parent = parent.parentElement.closest('.condition-group');
    }

    if (depth >= 4) {
        return;
    }

    const group = document.querySelector('#condition-group-template').content.firstElementChild.cloneNode(true);
    group.querySelector('[data-group-type]').name = '';
    group.querySelector('[data-group-operator]').name = '';
    group.querySelector('[data-group-type]').value = 'group';
    container.append(group);
    appendCondition(group.querySelector('[data-condition-container]'));
    updateConditionIndexes();
}

document.addEventListener('click', (event) => {
    if (!event.target.closest('#rule-builder-form')) {
        return;
    }

    const addConditionButton = event.target.closest('[data-add-condition]');
    const addGroupButton = event.target.closest('[data-add-group]');
    const removeButton = event.target.closest('[data-remove-condition]');

    if (addConditionButton) {
        appendCondition(addConditionButton.closest('[data-condition-container]'));
    } else if (addGroupButton) {
        appendGroup(addGroupButton.closest('[data-condition-container]'));
    } else if (removeButton && !removeButton.disabled) {
        removeButton.closest('[data-condition-node]').remove();
        updateConditionIndexes();
    }
});

document.addEventListener('change', (event) => {
    if (!event.target.closest('#rule-builder-form')) {
        return;
    }

    const fieldSelect = event.target.closest('[data-condition-field]');

    if (!fieldSelect) {
        return;
    }

    const row = fieldSelect.closest('.condition-row');
    const operator = row.querySelector('[data-condition-operator]');
    const operatorChanged = updateConditionRow(row, true);
    operator.name = fieldSelect.name.replace('[field]', '[operator]');
    bindLiveModel(operator, fieldSelect.getAttribute('wire:model.live').replace('.field', '.operator'));
    syncSizeHelp();

    if (operatorChanged) {
        operator.dispatchEvent(new Event('change', { bubbles: true }));
    }
});

function syncSizeHelp() {
    const sizeHelp = document.querySelector('[data-size-help]');

    if (sizeHelp) {
        sizeHelp.hidden = !Array.from(document.querySelectorAll('#rule-builder-form [data-condition-field]'))
            .some((field) => field.value === 'size');
    }
}

updateConditionIndexes();
const syncConditionRows = () => {
    document.querySelectorAll('#rule-builder-form .condition-row').forEach((row) => updateConditionRow(row));
    updateConditionIndexes();
    syncSizeHelp();
};
const conditionObserver = new MutationObserver(syncConditionRows);
conditionObserver.observe(document.body, { childList: true, subtree: true });
syncConditionRows();

function updateAutoPreviewCountdowns() {
    document.querySelectorAll('[data-auto-preview-countdown]').forEach((element) => {
        const deadline = Number(element.dataset.nextPreviewAt);

        if (!Number.isFinite(deadline)) {
            return;
        }

        const remaining = Math.max(0, Math.ceil((deadline * 1000 - Date.now()) / 1000));
        element.textContent = remaining === 0
            ? 'Automatic preview is running…'
            : `Next automatic preview in ${remaining} second${remaining === 1 ? '' : 's'}`;
    });
}

updateAutoPreviewCountdowns();
window.setInterval(updateAutoPreviewCountdowns, 1000);
