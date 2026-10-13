function paragraph(document, label, value) {
    const line = document.createElement('p');
    const name = document.createElement('strong');
    name.textContent = `${label}: `;
    line.append(name, document.createTextNode(value === null || value === undefined || value === '' ? 'No informado' : String(value)));
    return line;
}

/** Cuerpo sin acciones; cada entrada decide explícitamente cómo enlazar la ficha. */
export function treePopupContent(properties, {document, inspectionLabel = 'Última inspección'}) {
    const box = document.createElement('div');
    box.className = 'map-tree-popup';
    const title = document.createElement('h3');
    title.textContent = properties.code || 'Árbol';
    box.append(title);
    const commonNames = Array.isArray(properties.common_names) ? properties.common_names.join(', ') : properties.common_name;
    box.append(paragraph(document, 'Nombres comunes', commonNames));
    box.append(paragraph(document, 'Nombre científico', properties.scientific_name));
    box.append(paragraph(document, 'Estado', properties.status_label));
    box.append(paragraph(document, 'Emplazamiento', properties.placement));
    box.append(paragraph(document, inspectionLabel, properties.latest_inspection?.date));
    return box;
}
