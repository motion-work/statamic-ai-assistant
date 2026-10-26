<template>
  <AiInputWrapper :value="value" :inputType="'input'" :config="config" :handle="handle" @update:value="update" />
</template>
<script>
import { FieldtypeMixin as StatamicFieldtypeMixin } from '@statamic/cms';
import AiInputWrapper from './AiInputWrapper.vue';

export default {
  mixins: [StatamicFieldtypeMixin],
  components: {
    AiInputWrapper,
  },
};
</script>

