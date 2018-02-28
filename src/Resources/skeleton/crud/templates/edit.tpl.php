<?= $helper->getHeadPrintCode('Edit '.$entity_class_name) ?>

{% block stylesheets %}
<style>
    .example-wrapper { margin: 1em auto; max-width: 1018px; width: 95%; }
    .example-wrapper form div { margin: 1em 0; }
    .example-wrapper form label { display: block; }
    .example-actions form, .example-actions a { display: inline-block; margin: 0 2px; }
</style>
{% endblock %}

{% block body %}
    <div class="example-wrapper">
        <h1>Edit <?= $entity_class_name ?></h1>

        {{ include('<?= $route_name ?>/_form.html.twig', {'form': form, 'button_label': 'Edit'}) }}

        <div class="example-actions">
            <a href="{{ path('<?= $route_name ?>_index') }}">back to list</a>

            {{ include('<?= $route_name ?>/_delete_form.html.twig', {'<?= $entity_var_singular ?>': <?= $entity_var_singular ?>}) }}
        </div>
    </div>
{% endblock %}