function conditionNodes(container) {
    return Array.from(container.children).filter((child) => child.matches('[data-condition-node]'));
}

function bindLiveModel(element, property, debounce = false) {
    Array.from(element.attributes)
        .filter((attribute) => attribute.name.startsWith('wire:model'))
        .forEach((attribute) => element.removeAttribute(attribute.name));
    element.setAttribute(debounce ? 'wire:model.live.debounce.500ms' : 'wire:model.live', property);
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
    });
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

    const operatorSelect = fieldSelect.closest('.condition-row').querySelector('[data-condition-operator]');
    const operators = ['size', 'age'].includes(fieldSelect.value)
        ? [['equals', 'equals'], ['greater_than', 'is greater than'], ['less_than', 'is less than']]
        : [['contains', 'contains'], ['equals', 'equals'], ['starts_with', 'starts with'], ['ends_with', 'ends with']];

    operatorSelect.replaceChildren(...operators.map(([value, label]) => {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = label;

        return option;
    }));
    operatorSelect.value = operators[0][0];
    operatorSelect.name = fieldSelect.name.replace('[field]', '[operator]');
    bindLiveModel(operatorSelect, fieldSelect.getAttribute('wire:model.live').replace('.field', '.operator'));
    operatorSelect.dispatchEvent(new Event('change', { bubbles: true }));
});

function updateActionFields(actionSelect) {
    const form = actionSelect.closest('#rule-builder-form');
    const targetInput = form.querySelector('[data-rule-target]');
    const targetLabel = form.querySelector('[data-target-label]');
    const targetHelp = form.querySelector('[data-target-help]');
    const scriptWarning = form.querySelector('[data-script-warning]');
    const action = actionSelect.value;
    const labels = {
        rename: ['NEW FILE NAME', 'Use a name only; the file stays in its current folder.'],
        move: ['DESTINATION FOLDER', 'Enter an existing local folder. Files are never overwritten.'],
        copy: ['DESTINATION FOLDER', 'Enter an existing local folder. The original file remains in place.'],
        trash: ['DESTINATION', 'Files are moved to Keeper’s recoverable holding folder.'],
        script: ['COMMAND TO RUN', 'The matched file path is supplied in the KEEPER_FILE environment variable.'],
    };

    targetLabel.textContent = labels[action][0];
    targetHelp.textContent = labels[action][1];
    targetInput.placeholder = action === 'rename' ? 'invoice-2026.pdf' : action === 'script' ? 'your trusted command' : 'C:\\Users\\you\\Documents';
    targetInput.required = action !== 'trash';
    targetInput.hidden = action === 'trash';
    targetHelp.hidden = action === 'trash';
    scriptWarning.hidden = action !== 'script';
}

document.addEventListener('change', (event) => {
    const actionSelect = event.target.closest('[data-rule-action]');

    if (actionSelect) {
        updateActionFields(actionSelect);
    }
});

document.querySelectorAll('[data-rule-action]').forEach(updateActionFields);
updateConditionIndexes();
