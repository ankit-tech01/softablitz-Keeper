const conditionRoot = document.querySelector('#conditions');
const conditionTemplate = document.querySelector('#condition-row-template');
const groupTemplate = document.querySelector('#condition-group-template');

function conditionNodes(container) {
    return Array.from(container.children).filter((child) => child.matches('[data-condition-node]'));
}

function updateConditionIndexes(container = conditionRoot, prefix = 'conditions') {
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
            groupOperator.name = `${base}[operator]`;
            updateConditionIndexes(children, `${base}[conditions]`);

            return;
        }

        node.querySelector('[data-condition-field]').name = `${base}[field]`;
        node.querySelector('[data-condition-operator]').name = `${base}[operator]`;
        node.querySelector('[data-condition-value]').name = `${base}[value]`;
    });
}

function appendCondition(container) {
    if (conditionNodes(container).length >= 12) {
        return;
    }

    const row = conditionTemplate.content.firstElementChild.cloneNode(true);
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

    const group = groupTemplate.content.firstElementChild.cloneNode(true);
    group.querySelector('[data-group-type]').name = '';
    group.querySelector('[data-group-operator]').name = '';
    group.querySelector('[data-group-type]').value = 'group';
    container.append(group);
    appendCondition(group.querySelector('[data-condition-container]'));
    updateConditionIndexes();
}

conditionRoot?.addEventListener('click', (event) => {
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

conditionRoot?.addEventListener('change', (event) => {
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
    operatorSelect.name = fieldSelect.name.replace('[field]', '[operator]');
});

const actionSelect = document.querySelector('[data-rule-action]');
const targetInput = document.querySelector('[data-rule-target]');
const targetLabel = document.querySelector('[data-target-label]');
const targetHelp = document.querySelector('[data-target-help]');
const scriptWarning = document.querySelector('[data-script-warning]');

function updateActionFields() {
    if (!actionSelect || !targetInput || !targetLabel || !targetHelp || !scriptWarning) {
        return;
    }

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

actionSelect?.addEventListener('change', updateActionFields);
updateActionFields();
updateConditionIndexes();
