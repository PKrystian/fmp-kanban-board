<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\BoardColumn;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;

final class BoardColumnType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Column name',
                'attr' => [
                    'autocomplete' => 'off',
                    'maxlength' => 255,
                ],
                'constraints' => [
                    new NotBlank(),
                    new Length(max: 255),
                ],
            ])
            ->add('wipLimit', IntegerType::class, [
                'label' => 'WIP limit',
                'required' => false,
                'help' => 'Leave empty for no limit',
                'attr' => ['min' => 1],
                'constraints' => [new Positive()],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BoardColumn::class,
        ]);
    }
}
